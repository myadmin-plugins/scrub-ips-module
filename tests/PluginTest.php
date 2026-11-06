<?php

namespace Ganesh\MyadminScrubIps\Tests;

use Ganesh\MyadminScrubIps\Plugin;
use Ganesh\MyadminScrubIps\Tests\Support\DbDouble;
use Ganesh\MyadminScrubIps\Tests\Support\FrameworkSpy;
use Ganesh\MyadminScrubIps\Tests\Support\ScrubDouble;
use MyAdmin\App;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Behaviour of the scrub ip deactivate / terminate hooks: canceling a scrub ip
 * service removes the scrub firewall rules, geo rules, firewall list rules and
 * filters of its ip, unless the service is only expiring, was already canceled,
 * or another service still holds the ip. The hooks are dispatched for real and
 * asserted on by what they did.
 */
class PluginTest extends TestCase
{
    private const IP = '192.0.2.50';
    private const OTHER_IP = '192.0.2.99';
    private const SERVICE_TYPE = 11552;

    /** @var ScrubDouble|null */
    private $scrub;

    /** @var bool */
    private $clientBuilt = false;

    protected function setUp(): void
    {
        FrameworkSpy::reset();
        \MyAdmin\scrub_ips\Wanguard::$deleteStatus = 200;
        FrameworkSpy::$serviceTypes = [
            self::SERVICE_TYPE => ['services_type' => App::SCRUB_IPS_SERVICE_TYPE, 'services_name' => 'Current IP + Scrub'],
            7 => ['services_type' => 999, 'services_name' => 'Something else'],
        ];
        DbDouble::willReturnRowsFor('from scrub_ips where', []);
        DbDouble::willReturnRowsFor('from floating_ips where', []);
        $this->useScrub(new ScrubDouble());
    }

    protected function tearDown(): void
    {
        Plugin::$scrubFactory = null;
    }

    // ---------------------------------------------------------------
    //  Hooks
    // ---------------------------------------------------------------

    public function testHooksAreTheThreeModuleEvents(): void
    {
        $this->assertSame([
            'scrub_ips.load_processing' => [Plugin::class, 'loadProcessing'],
            'scrub_ips.settings' => [Plugin::class, 'getSettings'],
            'scrub_ips.deactivate' => [Plugin::class, 'getDeactivate'],
        ], Plugin::getHooks());
    }

    // ---------------------------------------------------------------
    //  Cancel
    // ---------------------------------------------------------------

    /**
     * Canceling a live scrub ip deletes every rule, list rule and filter listed for
     * its ip (and only those naming it), after the BGP announcement is withdrawn.
     */
    public function testCancelDeletesTheRulesListsAndFiltersOfItsIp(): void
    {
        $bpf = self::bpf(self::IP);
        $this->useScrub(new ScrubDouble([
            'firewallListByIp' => [
                ['id' => 4779, 'destination_ip' => $bpf, 'destination_port' => 22],
                ['id' => 4780, 'destination_ip' => $bpf, 'destination_port' => 3306],
            ],
            'geoFirewallListByIp' => [['id' => 1000, 'daddr' => $bpf, 'dest' => 0]],
            'firewallListsByIp' => [['id' => 1, 'daddr' => $bpf, 'dport' => 80]],
            'filterListAll' => [
                ['filter_name' => 'synproxy', 'daddr' => (string) $bpf, 'dest' => '22'],
                ['filter_name' => 'dns', 'daddr' => (string) $bpf, 'dest' => '53'],
                ['filter_name' => 'a2s_udp', 'daddr' => (string) $bpf, 'dest' => '27016'],
                ['filter_name' => 'ue5', 'daddr' => (string) $bpf, 'dest' => '9580'],
                ['filter_name' => 'adaptive_udp', 'daddr' => (string) $bpf, 'dest' => '7777'],
            ],
        ]));

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertSame([['delete', '981', 2]], array_map(function ($c) {
            return [$c[0], (string) $c[1], $c[2]];
        }, FrameworkSpy::$wanguard), 'the announcement is still withdrawn');
        $this->assertContains(['firewallListByIp', $bpf], $this->scrub->calls);
        $this->assertContains(['geoFirewallListByIp', $bpf], $this->scrub->calls);
        $this->assertContains(['firewallListsByIp', $bpf], $this->scrub->calls);
        $this->assertContains(['filterListAll', ['daddr' => $bpf]], $this->scrub->calls);
        $this->assertSame([['firewallDelete', 4779], ['firewallDelete', 4780]], $this->scrub->callsTo('firewallDelete'));
        $this->assertSame([['geoFirewallDelete', 1000]], $this->scrub->callsTo('geoFirewallDelete'));
        $this->assertSame([['firewallListDelete', 1]], $this->scrub->callsTo('firewallListDelete'));
        $this->assertSame([
            ['filterDeleteByType', 'synproxy', ['filtered_ip' => $bpf, 'filtered_port' => 22]],
            ['filterDeleteByType', 'dns', ['daddr' => $bpf, 'dest' => 53]],
            ['filterDeleteByType', 'a2s', ['daddr' => $bpf, 'dest' => 27016]],
            ['filterDeleteByType', 'unrealengine5', ['daddr' => $bpf, 'dest' => 9580]],
            ['filterDeleteByType', 'udp_adaptive', ['daddr' => $bpf, 'dest' => 7777]],
        ], $this->scrub->callsTo('filterDeleteByType'));
        $this->assertSame([], FrameworkSpy::$notifications);
        $done = array_filter(FrameworkSpy::logsWithLevel('info'), static function (array $log) {
            return strpos((string) $log[2], 'removed 9 rule(s)/filter(s)') !== false;
        });
        $this->assertCount(1, $done);
    }

    /**
     * The holder checks look for another scrub ip on the ip that is not canceled,
     * and for a floating ip + scrub service on it that is not canceled.
     */
    public function testCancelChecksForOtherHoldersOfTheIp(): void
    {
        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $scrubHolder = DbDouble::queriesMatching('from scrub_ips where');
        $this->assertCount(1, $scrubHolder);
        $this->assertStringContainsString("scrub_ip_ip='" . self::IP . "'", $scrubHolder[0]);
        $this->assertStringContainsString('scrub_ip_id != 4242', $scrubHolder[0]);
        $this->assertStringContainsString("scrub_ip_status != 'canceled'", $scrubHolder[0]);
        $floatingHolder = DbDouble::queriesMatching('from floating_ips where');
        $this->assertCount(1, $floatingHolder);
        $this->assertStringContainsString("floating_ip_ip='" . self::IP . "'", $floatingHolder[0]);
        $this->assertStringContainsString('floating_ip_type in (11545,11552)', $floatingHolder[0]);
        $this->assertStringContainsString("floating_ip_status != 'canceled'", $floatingHolder[0]);
        $this->assertTrue($this->clientBuilt);
    }

    /**
     * An entry the api lists for the ip but that names another destination is left
     * alone and staff are told.
     */
    public function testEntriesForAnotherDestinationAreLeftAlone(): void
    {
        $bpf = self::bpf(self::IP);
        $other = self::bpf(self::OTHER_IP);
        $this->useScrub(new ScrubDouble([
            'firewallListByIp' => [['id' => 1, 'destination_ip' => $other], ['id' => 2, 'destination_ip' => $bpf]],
            'firewallListsByIp' => [['id' => 3, 'daddr' => $other]],
            'filterListAll' => [['filter_name' => 'dns', 'daddr' => (string) $other, 'dest' => '53']],
        ]));

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertSame([['firewallDelete', 2]], $this->scrub->callsTo('firewallDelete'));
        $this->assertSame([], $this->scrub->callsTo('firewallListDelete'));
        $this->assertSame([], $this->scrub->callsTo('filterDeleteByType'));
        $this->assertCount(1, FrameworkSpy::$notifications);
        $this->assertStringContainsString('left alone', FrameworkSpy::$notifications[0][0]);
    }

    /**
     * Expiry (billingd's cancel_service(..., 'expired')) keeps the rules: the ip stays
     * with the customer and a paid invoice reactivates the service.
     */
    public function testExpiryKeepsTheRules(): void
    {
        $this->dispatchDeactivate(self::SERVICE_TYPE, 'active', 'expired');

        $this->assertFalse($this->clientBuilt, 'an expiring service keeps its rules');
        $this->assertSame([], DbDouble::queriesMatching('from scrub_ips where'));
        $this->assertCount(1, FrameworkSpy::$wanguard, 'the announcement is withdrawn as before');
        $skips = array_filter(FrameworkSpy::logsWithLevel('info'), static function (array $log) {
            return strpos((string) $log[2], 'expiring') !== false;
        });
        $this->assertCount(1, $skips);
    }

    /**
     * Canceling a service that is already canceled (staff can) never cleans up: the
     * ip may belong to someone else by now.
     */
    public function testRecancelOfACanceledServiceLeavesTheRulesAlone(): void
    {
        $this->dispatchDeactivate(self::SERVICE_TYPE, 'canceled');

        $this->assertFalse($this->clientBuilt);
        $this->assertSame([], DbDouble::queriesMatching('from scrub_ips where'));
    }

    /**
     * Canceling an expired service cleans up: expiry kept the rules for a renewal
     * that is not coming, and nothing else releases a scrub ip.
     */
    public function testCancelOfAnExpiredServiceCleansUp(): void
    {
        $this->useScrub(new ScrubDouble(['firewallListByIp' => [['id' => 5, 'destination_ip' => self::bpf(self::IP)]]]));

        $this->dispatchDeactivate(self::SERVICE_TYPE, 'expired');

        $this->assertSame([['firewallDelete', 5]], $this->scrub->callsTo('firewallDelete'));
    }

    /**
     * Another scrub ip service on the same ip that is not canceled (active, pending,
     * expired) still uses the rules: nothing is deleted.
     */
    public function testAnotherScrubIpOnTheIpKeepsTheRules(): void
    {
        DbDouble::willReturnRowsFor('from scrub_ips where', [['scrub_ip_id' => 2100]]);

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertFalse($this->clientBuilt);
        $this->assertStringContainsString('held by scrub ip 2100', FrameworkSpy::logsWithLevel('warning')[0][2]);
    }

    /**
     * A floating ip + scrub service on the same ip manages the same scrub rules from
     * its own page: nothing is deleted.
     */
    public function testAFloatingIpScrubServiceOnTheIpKeepsTheRules(): void
    {
        DbDouble::willReturnRowsFor('from floating_ips where', [['floating_ip_id' => 1500]]);

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertFalse($this->clientBuilt);
        $this->assertStringContainsString('held by floating ip 1500', FrameworkSpy::logsWithLevel('warning')[0][2]);
    }

    /**
     * Deactivating a service of another type touches neither the database nor the
     * scrub api.
     */
    public function testOtherServiceTypesAreIgnored(): void
    {
        $this->dispatchDeactivate(7);

        $this->assertFalse($this->clientBuilt);
        $this->assertSame([], DbDouble::$queries);
        $this->assertSame([], FrameworkSpy::$wanguard);
    }

    // ---------------------------------------------------------------
    //  Failures never block the cancel
    // ---------------------------------------------------------------

    /**
     * A delete the api refuses is reported to staff; the rest is still deleted and
     * the cancel goes on.
     */
    public function testARefusedDeleteIsReportedAndDoesNotBlockTheCancel(): void
    {
        $scrub = new ScrubDouble([
            'firewallListByIp' => [['id' => 5, 'destination_ip' => self::bpf(self::IP)]],
            'filterListAll' => [['filter_name' => 'dns', 'daddr' => (string) self::bpf(self::IP), 'dest' => '53']],
        ]);
        $scrub->deleteResponse = ['404 page not found'];
        $this->useScrub($scrub);

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertCount(1, $scrub->callsTo('firewallDelete'));
        $this->assertCount(1, $scrub->callsTo('filterDeleteByType'), 'one failure does not stop the others');
        $this->assertCount(1, FrameworkSpy::$notifications);
        $this->assertStringContainsString('did not finish', FrameworkSpy::$notifications[0][0]);
        $this->assertStringContainsString('firewall rule 5', FrameworkSpy::$notifications[0][0]);
        $this->assertNotEmpty(FrameworkSpy::logsWithLevel('error'));
    }

    /**
     * The scrub api no longer serves a geo rule listing (every geo GET answers 404):
     * that is reported on its own as geo rules that could not be listed/verified, and
     * the other kinds still count as cleaned up.
     */
    public function testAnUnlistableGeoListingIsItsOwnWarning(): void
    {
        $scrub = new ScrubDouble([
            'geoFirewallListByIp' => ["404 page not found\n"],
            'firewallListByIp' => [['id' => 5, 'destination_ip' => self::bpf(self::IP)]],
        ]);
        $this->useScrub($scrub);

        $this->assertTrue(Plugin::cleanupScrub(4242, self::IP));

        $this->assertSame([['firewallDelete', 5]], $scrub->callsTo('firewallDelete'));
        $this->assertCount(1, FrameworkSpy::$notifications);
        $message = FrameworkSpy::$notifications[0][0];
        $this->assertStringContainsString('geo firewall rules could not be listed/verified', $message);
        $this->assertStringContainsString('404 page not found', $message);
        $this->assertStringContainsString('1 removed', $message);
        $this->assertStringNotContainsString('did not finish', $message);
        $this->assertSame([], FrameworkSpy::logsWithLevel('error'));
        $this->assertCount(1, FrameworkSpy::logsWithLevel('warning'));
    }

    /**
     * When another kind fails as well, the one hand-off message says the cleanup did
     * not finish and still names the unverified geo rules.
     */
    public function testAFailureAndAnUnlistableGeoListingShareOneHandOff(): void
    {
        $this->useScrub(new ScrubDouble([
            'geoFirewallListByIp' => ["404 page not found\n"],
            'firewallListsByIp' => ['error' => 'db down'],
            'filterListAll' => [['filter_name' => 'dns', 'daddr' => (string) self::bpf(self::IP), 'dest' => '53']],
        ]));

        $this->assertFalse(Plugin::cleanupScrub(4242, self::IP));

        $this->assertCount(1, $this->scrub->callsTo('filterDeleteByType'), 'a failed listing does not stop the other kinds');
        $this->assertCount(1, FrameworkSpy::$notifications);
        $message = FrameworkSpy::$notifications[0][0];
        $this->assertStringContainsString('did not finish', $message);
        $this->assertStringContainsString('firewall lists: listing failed', $message);
        $this->assertStringContainsString('also its geo firewall rules could not be listed/verified', $message);
    }

    /**
     * The first exception from the api (unreachable / timed out) stops the cleanup:
     * one timeout, not one per call. The kinds not reached are named.
     */
    public function testAnUnreachableApiStopsTheCleanupAndIsReported(): void
    {
        $this->useScrub(new ScrubDouble([
            'firewallListByIp' => new \Exception('Connection Error: 28 - Operation timed out'),
            'firewallListsByIp' => [['id' => 9, 'daddr' => self::bpf(self::IP)]],
        ]));

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertSame([['firewallListByIp', self::bpf(self::IP)]], $this->scrub->calls, 'nothing after the failed call');
        $this->assertCount(1, FrameworkSpy::$notifications);
        $message = FrameworkSpy::$notifications[0][0];
        $this->assertStringContainsString('timed out', $message);
        $this->assertStringContainsString('not checked: firewall lists, filters, geo firewall rules', $message);
    }

    /**
     * An exception from a delete stops the cleanup too.
     */
    public function testAnExceptionFromADeleteStopsTheCleanup(): void
    {
        $scrub = new ScrubDouble([
            'firewallListByIp' => [['id' => 5, 'destination_ip' => self::bpf(self::IP)], ['id' => 6, 'destination_ip' => self::bpf(self::IP)]],
            'firewallListsByIp' => [['id' => 9, 'daddr' => self::bpf(self::IP)]],
        ]);
        $scrub->deleteResponse = new \Exception('Connection Error: 7 - Failed to connect');
        $this->useScrub($scrub);

        $this->assertFalse(Plugin::cleanupScrub(4242, self::IP));

        $this->assertSame([['firewallDelete', 5]], $scrub->callsTo('firewallDelete'));
        $this->assertSame([], $scrub->callsTo('firewallListsByIp'));
        $this->assertStringContainsString('Failed to connect', FrameworkSpy::$notifications[0][0]);
    }

    /**
     * A firewall list delete that answers with the rule itself (its id) succeeded, as
     * the client firewall lists page counts it.
     */
    public function testAFirewallListDeleteAnsweringWithItsIdCounts(): void
    {
        $scrub = new ScrubDouble(['firewallListsByIp' => [['id' => 9, 'daddr' => self::bpf(self::IP)]]]);
        $scrub->deleteResponse = ['id' => 9, 'daddr' => self::bpf(self::IP)];
        $this->useScrub($scrub);

        $this->assertTrue(Plugin::cleanupScrub(4242, self::IP));

        $this->assertSame([['firewallListDelete', 9]], $scrub->callsTo('firewallListDelete'));
        $this->assertSame([], FrameworkSpy::$notifications);
    }

    /**
     * A filter whose name or port is not a plain one is reported, never sent.
     */
    public function testAFilterWithAnOddNameOrPortIsNotSent(): void
    {
        $bpf = (string) self::bpf(self::IP);
        $this->useScrub(new ScrubDouble([
            'filterListAll' => [
                ['filter_name' => '../firewall', 'daddr' => $bpf, 'dest' => '53'],
                ['filter_name' => 'dns', 'daddr' => $bpf, 'dest' => '53; x'],
                ['filter_name' => "dns\n", 'daddr' => $bpf, 'dest' => '53'],
            ],
        ]));

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertSame([], $this->scrub->callsTo('filterDeleteByType'));
        $this->assertCount(1, FrameworkSpy::$notifications);
        $this->assertStringContainsString('unknown filter type or port', FrameworkSpy::$notifications[0][0]);
    }

    /**
     * Each listed filter goes to the route core's Scrub::filterRouteName() gives it
     * (the mapping the filter pages and Scrub::filterCreate()/filterDelete() use since
     * the scrub api stopped sending filter_uri): a route that carries an underscore
     * (udp_adaptive, cs2_udp) is its own route, the rest route by their first part.
     */
    public function testFiltersGoToTheSameRoutesAsCore(): void
    {
        $bpf = self::bpf(self::IP);
        $routes = [
            'udp_adaptive' => 'udp_adaptive',
            'cs2_udp' => 'cs2_udp',
            'cs2' => 'cs2_udp',
            'sip_udp' => 'genericsip',
            'ue5_udp' => 'unrealengine5',
            'raknet_udp' => 'raknet',
            'genericsip' => 'genericsip',
            'unrealengine5' => 'unrealengine5',
            'palworld' => 'palworld',
            'mcje' => 'mcje',
        ];
        $listed = [];
        $port = 1000;
        foreach (array_keys($routes) as $name) {
            $listed[] = ['filter_name' => $name, 'daddr' => (string) $bpf, 'dest' => (string) $port++];
        }
        $this->useScrub(new ScrubDouble(['filterListAll' => $listed]));

        $this->dispatchDeactivate(self::SERVICE_TYPE);

        $this->assertSame(array_values($routes), array_column($this->scrub->callsTo('filterDeleteByType'), 1));
        $this->assertSame([], FrameworkSpy::$notifications);
    }

    /**
     * cleanupScrub() refuses anything but a single IPv4 address.
     */
    public function testCleanupRefusesAnInvalidIp(): void
    {
        foreach (['', '0', '192.0.2.0/24', '2001:db8::1', 'x'] as $ip) {
            FrameworkSpy::reset();
            $this->clientBuilt = false;

            $this->assertFalse(Plugin::cleanupScrub(4242, $ip));

            $this->assertFalse($this->clientBuilt, 'nothing is listed for ' . json_encode($ip));
            $this->assertCount(1, FrameworkSpy::$notifications);
        }
    }

    // ---------------------------------------------------------------
    //  Terminate
    // ---------------------------------------------------------------

    /**
     * Terminate (the service is finished) cleans the ip up too, after marking the
     * service canceled and withdrawing its announcement.
     */
    public function testTerminateCleansTheRules(): void
    {
        $this->useScrub(new ScrubDouble(['firewallListByIp' => [['id' => 5, 'destination_ip' => self::bpf(self::IP)]]]));

        $this->runTerminate();

        $this->assertContains(['setStatus', 'canceled'], FrameworkSpy::$orm);
        $this->assertCount(1, FrameworkSpy::$wanguard);
        $this->assertSame([['firewallDelete', 5]], $this->scrub->callsTo('firewallDelete'));
        $this->assertStringContainsString('scrub_ip_id != 4242', DbDouble::queriesMatching('from scrub_ips where')[0]);
    }

    /**
     * Terminate leaves the rules of an ip another service holds.
     */
    public function testTerminateOfAHeldIpLeavesTheRules(): void
    {
        DbDouble::willReturnRowsFor('from scrub_ips where', [['scrub_ip_id' => 2100]]);

        $this->runTerminate();

        $this->assertFalse($this->clientBuilt);
        $this->assertContains(['setStatus', 'canceled'], FrameworkSpy::$orm);
    }

    /**
     * Whatever goes wrong, cleanupReleasedIp() does not throw, and staff are told.
     */
    public function testCleanupReleasedIpNeverThrows(): void
    {
        $db = new class () {
            public function query($sql, $line = null, $file = null)
            {
                throw new \RuntimeException('server has gone away');
            }

            public function real_escape($value)
            {
                return (string) $value;
            }
        };

        $this->assertFalse(Plugin::cleanupReleasedIp(4242, self::IP, $db));

        $this->assertFalse($this->clientBuilt);
        $this->assertCount(1, FrameworkSpy::$notifications);
        $this->assertStringContainsString('server has gone away', FrameworkSpy::$notifications[0][0]);
        $this->assertNotEmpty(FrameworkSpy::logsWithLevel('error'));
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    private function useScrub(ScrubDouble $scrub): void
    {
        $this->scrub = $scrub;
        $this->clientBuilt = false;
        Plugin::$scrubFactory = function () {
            $this->clientBuilt = true;
            return $this->scrub;
        };
    }

    /**
     * The ip in the byte order the scrub api keys by (core's uint_ip_to_bpf()).
     */
    private static function bpf(string $ip): int
    {
        return (int) sprintf('%u', ip2long(implode('.', array_reverse(explode('.', $ip)))));
    }

    /**
     * Dispatch scrub_ips.deactivate through a real dispatcher with the hooks the
     * plugin advertises, the way cancel_service() does (status not yet changed).
     *
     * @param int|string $serviceType
     */
    private function dispatchDeactivate($serviceType, string $status = 'active', string $newStatus = 'canceled'): void
    {
        $dispatcher = new EventDispatcher();
        foreach (Plugin::getHooks() as $hookName => $callback) {
            $dispatcher->addListener($hookName, $callback);
        }
        $service = new class ($serviceType, $status, self::IP) {
            private $type;
            private $status;
            private $ip;
            public $extra;

            public function __construct($type, string $status, string $ip)
            {
                $this->type = $type;
                $this->status = $status;
                $this->ip = $ip;
                $this->extra = json_encode(['status' => 201, 'response' => json_encode(['href' => '/wanguard-api/v1/bgp_announcements/981'])]);
            }

            public function getId()
            {
                return 4242;
            }

            public function getCustid()
            {
                return 777;
            }

            public function getType()
            {
                return $this->type;
            }

            public function getStatus()
            {
                return $this->status;
            }

            public function getIp()
            {
                return $this->ip;
            }

            public function getExtra()
            {
                return $this->extra;
            }

            public function setExtra($extra)
            {
                $this->extra = $extra;
                return $this;
            }

            public function save()
            {
                return true;
            }
        };

        $dispatcher->dispatch(new GenericEvent($service, ['new_status' => $newStatus]), Plugin::$module . '.deactivate');
    }

    /**
     * Run the setTerminate closure loadProcessing() registers.
     */
    private function runTerminate(): void
    {
        $serviceInfo = [
            'scrub_ip_id' => 4242,
            'scrub_ip_type' => self::SERVICE_TYPE,
            'scrub_ip_ip' => self::IP,
            'scrub_ip_custid' => 777,
            'scrub_ip_extra' => json_encode(['status' => 201, 'response' => json_encode(['href' => '/wanguard-api/v1/bgp_announcements/981'])]),
        ];
        $handler = new class ($serviceInfo) {
            public $closures = [];
            private $serviceInfo;

            public function __construct(array $serviceInfo)
            {
                $this->serviceInfo = $serviceInfo;
            }

            public function __call($name, $args)
            {
                if (strpos($name, 'set') === 0 && isset($args[0]) && is_callable($args[0])) {
                    $this->closures[substr($name, 3)] = $args[0];
                }
                return $this;
            }

            public function getServiceInfo()
            {
                return $this->serviceInfo;
            }
        };
        Plugin::loadProcessing(new GenericEvent($handler));
        $this->assertArrayHasKey('Terminate', $handler->closures);
        ($handler->closures['Terminate'])($handler);
    }
}
