<?php

namespace Ganesh\MyadminScrubIps;

use MyAdmin\scrub_ips\Wanguard;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Class Plugin
 *
 * @package Ganesh\MyAdminScrubIps
 */
class Plugin
{
    public static $name = 'Scrub IP Services';
    public static $description = 'Allows selling of Scrub IP Services';
    public static $help = '';
    public static $module = 'scrub_ips';
    public static $type = 'module';
    public static $settings = [
    	'SERVICE_ID_OFFSET' => 12000,
	    'USE_REPEAT_INVOICE' => true,
	    'USE_PACKAGES' => true,
	    'BILLING_DAYS_OFFSET' => 0,
	    'IMGNAME' => 'e-mail.png',
	    'REPEAT_BILLING_METHOD' => PRORATE_BILLING,
	    'DELETE_PENDING_DAYS' => 45,
	    'SUSPEND_DAYS' => 14,
	    'SUSPEND_WARNING_DAYS' => 7,
	    'TITLE' => 'Scrub IPs',
	    'MENUNAME' => 'Scrub IPs',
	    'EMAIL_FROM' => 'support@interserver.net',
	    'TBLNAME' => 'Scrub IPs',
	    'TABLE' => 'scrub_ips',
	    'TITLE_FIELD' => 'scrub_ip_ip',
	    'PREFIX' => 'scrub_ip'
	];

    /**
     * Floating ip packages whose own page manages scrub firewall rules and filters for
     * the floating ip (ViewFloatingIp::floatingIpScrubPackage(): 11545 Floating IP + Scrub;
     * it also lists 11552). A live one of these on the same ip shares the rules.
     */
    public const FLOATING_SCRUB_SERVICE_TYPES = [11545, 11552];

    /**
     * Builds the Scrub API client for cleanupScrub(); tests swap in a double.
     * @var callable|null fn(): object|string (an object or class name) with firewallListByIp(),
     *      firewallDelete(), geoFirewallListByIp(), geoFirewallDelete(), firewallListsByIp(),
     *      firewallListDelete(), filterListAll() and filterDeleteByType()
     */
    public static $scrubFactory = null;

    /**
     * Plugin constructor.
     */
    public function __construct()
    {
    }

    /**
     * @return array
     */
    public static function getHooks()
    {
        return [
            self::$module.'.load_processing' => [__CLASS__, 'loadProcessing'],
            self::$module.'.settings' => [__CLASS__, 'getSettings'],
            self::$module.'.deactivate' => [__CLASS__, 'getDeactivate']
        ];
    }

    /**
     * @param \Symfony\Component\EventDispatcher\GenericEvent $event
     */
    public static function getDeactivate(GenericEvent $event)
    {
        $serviceTypes = run_event('get_service_types', false, self::$module);
        $serviceClass = $event->getSubject();
        $settings = get_module_settings(self::$module);
        myadmin_log(self::$module, 'info', self::$name.' Deactivation', __LINE__, __FILE__, self::$module, $serviceClass->getId());
        if ($serviceTypes[$serviceClass->getType()]['services_type'] == get_service_define('SCRUB_IPS')) {
        	$service_extra = $serviceClass->getExtra();
            if (!empty($service_extra)) {
            	$tmp = json_decode($service_extra, true);
            	$tmp1 = json_decode($tmp['response'], true);
            	$w_id = str_replace('/wanguard-api/v1/bgp_announcements/', '', $tmp1['href']);
            	if (intval($w_id) > 0) {
            		$deleted = Wanguard::delete($w_id);
            		if ($deleted['status'] != 200) {
            			myadmin_log('myadmin', 'info', 'Unable to delete wangaurdID -'.$w_id.' Scrub IP. ServiceId - '.$serviceClass->getId(), __LINE__, __FILE__);
            			$smarty = new \TFSmarty();
		                $smarty->assign('module', self::$module);
		                $smarty->assign('id', $serviceClass->getId());
		                $smarty->assign('settings', $settings);
		                $email = $smarty->fetch('email/admin/deactivate_error.tpl');
		                $subject = 'ScrubIps Deactivation error ID';
		                (new \MyAdmin\Mail())->adminMail($subject, $email, false, 'admin/deactivate_error.tpl');
            		} else {
                        $serviceClass->setExtra('')->save();
                    }
            	}
            }
            $ip = (string) $serviceClass->getIp();
            $newStatus = $event->hasArgument('new_status') ? (string) $event->getArgument('new_status') : '';
            if ($newStatus === 'expired') {
                // expiry keeps the ip with the customer and a paid invoice reactivates the service
                // (setEnable/setReactivate re-announce the same ip), so the rules stay for that
                myadmin_log(self::$module, 'info', 'Scrub cleanup skipped for scrub ip '.$serviceClass->getId().': it is expiring, its rules stay for a renewal', __LINE__, __FILE__, self::$module, $serviceClass->getId());
            } elseif (self::scrubCleanupAllowed($serviceClass, $ip, get_module_db(self::$module))) {
                // the service is gone: whoever uses the ip next must not inherit its rules and filters
                self::cleanupScrub($serviceClass->getId(), $ip);
            }
        }
    }

    /**
     * Whether this deactivation may clean up the ip's scrub rules and filters.
     *
     * cancel_service() does not refuse a service that is already canceled (staff can
     * cancel again), and a canceled row keeps its scrub_ip_ip while the ip can move on
     * to another customer, so a second cancel never cleans up. An expired service is
     * cleaned when it is canceled: expiry kept its rules for a renewal that is not
     * coming, and nothing else ever releases a scrub ip (billingd never terminates
     * scrub_ips). Nothing is cleaned while another service still holds the ip.
     *
     * @param object                 $serviceClass the service being deactivated (status not yet changed)
     * @param string                 $ip
     * @param \MyDb\Mysqli\Db|object $db
     * @return bool
     */
    private static function scrubCleanupAllowed($serviceClass, $ip, $db)
    {
        $serviceId = $serviceClass->getId();
        $status = (string) $serviceClass->getStatus();
        if ($status === 'canceled') {
            myadmin_log(self::$module, 'info', 'Scrub cleanup skipped for scrub ip '.$serviceId.': it was already canceled before this cancel', __LINE__, __FILE__, self::$module, $serviceId);
            return false;
        }
        return !self::ipHeldByAnother($serviceId, $ip, $db);
    }

    /**
     * Whether another service still uses the scrub rules of the ip (logged when one does):
     * another scrub ip service on it that is not canceled (an expired one keeps its rules
     * for a renewal), or a floating ip + scrub service (FLOATING_SCRUB_SERVICE_TYPES) on it
     * that is not canceled. A plain floating ip has no scrub rules of its own, so a scrub ip
     * service ordered on it is cleaned up like any other.
     *
     * @param int|string             $serviceId
     * @param string                 $ip
     * @param \MyDb\Mysqli\Db|object $db
     * @return bool
     */
    private static function ipHeldByAnother($serviceId, $ip, $db)
    {
        $safeIp = $db->real_escape((string) $ip);
        $db->query("select scrub_ip_id from scrub_ips where scrub_ip_ip='{$safeIp}' and scrub_ip_id != ".(int) $serviceId." and scrub_ip_status != 'canceled' limit 1", __LINE__, __FILE__);
        if ($db->num_rows() > 0) {
            $db->next_record(MYSQL_ASSOC);
            myadmin_log(self::$module, 'warning', 'Scrub cleanup skipped for scrub ip '.$serviceId.': '.$ip.' is held by scrub ip '.($db->Record['scrub_ip_id'] ?? '?'), __LINE__, __FILE__, self::$module, $serviceId);
            return true;
        }
        $db->query("select floating_ip_id from floating_ips where floating_ip_ip='{$safeIp}' and floating_ip_type in (".implode(',', array_map('intval', self::FLOATING_SCRUB_SERVICE_TYPES)).") and floating_ip_status != 'canceled' limit 1", __LINE__, __FILE__);
        if ($db->num_rows() > 0) {
            $db->next_record(MYSQL_ASSOC);
            myadmin_log(self::$module, 'warning', 'Scrub cleanup skipped for scrub ip '.$serviceId.': '.$ip.' is held by floating ip '.($db->Record['floating_ip_id'] ?? '?'), __LINE__, __FILE__, self::$module, $serviceId);
            return true;
        }
        return false;
    }

    /**
     * Cleans up the scrub rules and filters of a scrub ip service that is being
     * terminated (setTerminate()), unless another service still holds the ip. It never
     * throws: a failure is logged and posted to chat, and the caller goes on.
     *
     * @param int|string                  $serviceId scrub ip service id
     * @param string                      $ip        its ip
     * @param \MyDb\Mysqli\Db|object|null $db        a handle that is free to run a query (default: a clone of the module's)
     * @return bool true when the cleanup ran and finished
     */
    public static function cleanupReleasedIp($serviceId, $ip, $db = null)
    {
        try {
            if ($db === null) {
                // a clone, so a caller iterating the module handle keeps its result set
                $db = clone get_module_db(self::$module);
            }
            if (self::ipHeldByAnother($serviceId, $ip, $db)) {
                return false;
            }
            return self::cleanupScrub($serviceId, $ip);
        } catch (\Throwable $e) {
            myadmin_log(self::$module, 'error', 'Scrub cleanup for released scrub ip '.$serviceId.' ('.json_encode($ip).') failed: '.get_class($e).': '.$e->getMessage(), __LINE__, __FILE__, self::$module, $serviceId);
            chatNotify('Scrub IP '.$serviceId.' ('.json_encode($ip).') was terminated but its scrub cleanup failed ('.$e->getMessage().') - please remove its rules/filters by hand', 'notifications');
            return false;
        }
    }

    /**
     * Deletes every scrub firewall rule, firewall list rule, filter and geo firewall
     * rule listed for a canceled scrub ip.
     *
     * Runs from getDeactivate() only for the cancel (not the expiry) of a service that
     * was not already canceled and whose ip no other service holds
     * (scrubCleanupAllowed()), and from cleanupReleasedIp() on terminate. Each kind is
     * listed for this ip and every entry is deleted one by one through the same per-id
     * calls the client pages use; an entry whose destination is not this ip is left
     * alone. It never throws and never stops the cancel: a failure is logged and posted
     * to chat for staff to finish by hand. The first exception from the scrub api (it
     * throws when the host cannot be reached or times out) stops the cleanup, so an
     * unreachable host costs one timeout, not one per call; the kinds not reached are
     * named in the report.
     *
     * The scrub api no longer serves a geo rule listing (/geo-firewall/rules/daddr/{ip}
     * and every other geo GET answer 404 since the early november 2026 api update), so
     * a geo listing that is not a list is reported on its own as "could not be
     * listed/verified" and does not make the other kinds' cleanup unfinished.
     *
     * @param int|string $serviceId scrub ip service id (for the logs)
     * @param string     $ip        the scrub ip
     * @return bool true when everything listed was deleted (geo rules that could not be listed are only warned about)
     */
    public static function cleanupScrub($serviceId, $ip)
    {
        $ip = is_string($ip) ? trim($ip) : '';
        $label = 'Scrub IP '.$serviceId.' ('.$ip.')';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            myadmin_log(self::$module, 'error', 'Scrub cleanup skipped for scrub ip '.$serviceId.': invalid ip '.json_encode($ip), __LINE__, __FILE__, self::$module, $serviceId);
            chatNotify($label.' canceled but its scrub rules/filters were not cleaned up: invalid ip', 'notifications');
            return false;
        }
        $bpf = self::bpfIp($ip);
        $deleted = 0;
        $failed = [];
        $skipped = [];
        $geoUnverified = null;
        $kinds = ['firewall rules', 'firewall lists', 'filters', 'geo firewall rules'];
        $kind = 'scrub api client';
        try {
            $scrub = self::$scrubFactory !== null ? call_user_func(self::$scrubFactory) : \MyAdmin\scrub_ips\Scrub::class;
            foreach ($kinds as $kind) {
                $listing = null;
                if ($kind === 'firewall rules') {
                    // [{id, destination_ip, ...}], deleted with "ok"
                    $listed = self::deleteListed($scrub, 'firewall rule', 'firewallListByIp', 'firewallDelete', 'destination_ip', $bpf, $deleted, $failed, $skipped, $listing);
                } elseif ($kind === 'firewall lists') {
                    // [{id, daddr, dport, ...}], deleted with "ok" or the rule back
                    $listed = self::deleteListed($scrub, 'firewall list', 'firewallListsByIp', 'firewallListDelete', 'daddr', $bpf, $deleted, $failed, $skipped, $listing);
                } elseif ($kind === 'filters') {
                    // [{filter_name, daddr, dest}], no id: deleted by type + daddr/dest
                    $listed = self::deleteFilters($scrub, $bpf, $deleted, $failed, $skipped, $listing);
                } else {
                    // [{id, daddr, ...}] while the api served it
                    $listed = self::deleteListed($scrub, 'geo firewall rule', 'geoFirewallListByIp', 'geoFirewallDelete', 'daddr', $bpf, $deleted, $failed, $skipped, $listing);
                }
                if (!$listed) {
                    if ($kind === 'geo firewall rules') {
                        $geoUnverified = $listing;
                    } else {
                        $failed[] = $kind.': listing failed ('.$listing.')';
                    }
                }
            }
        } catch (\Throwable $e) {
            $rest = array_slice($kinds, (int) array_search($kind, $kinds, true) + 1);
            $failed[] = $kind.': '.get_class($e).': '.$e->getMessage().' - stopped there'
                .($kind !== 'scrub api client' && !empty($rest) ? ', not checked: '.implode(', ', $rest) : '');
        }
        $geoNote = $geoUnverified !== null ? 'its geo firewall rules could not be listed/verified (the scrub api answered '.$geoUnverified.' to the geo listing)' : '';
        if (!empty($skipped)) {
            // listed for this ip but naming another destination: leave it, but tell staff
            myadmin_log(self::$module, 'warning', 'Scrub cleanup for scrub ip '.$serviceId.' ('.$ip.') left '.implode('; ', $skipped), __LINE__, __FILE__, self::$module, $serviceId);
            chatNotify($label.' canceled; the scrub api listed entries for it that do not name the ip and were left alone: '.implode('; ', $skipped).' - please check them by hand', 'notifications');
        }
        if (empty($failed)) {
            myadmin_log(self::$module, 'info', 'Scrub cleanup for scrub ip '.$serviceId.' ('.$ip.') removed '.$deleted.' rule(s)/filter(s)', __LINE__, __FILE__, self::$module, $serviceId);
            if ($geoNote !== '') {
                myadmin_log(self::$module, 'warning', 'Scrub cleanup for scrub ip '.$serviceId.' ('.$ip.'): '.$geoNote, __LINE__, __FILE__, self::$module, $serviceId);
                chatNotify($label.' canceled: its firewall rules, firewall lists and filters were cleaned up ('.$deleted.' removed), but '.$geoNote.' - please check its geo rules by hand', 'notifications');
            }
            return true;
        }
        myadmin_log(self::$module, 'error', 'Scrub cleanup for scrub ip '.$serviceId.' ('.$ip.') removed '.$deleted.', failed: '.implode('; ', $failed).($geoNote !== '' ? '; '.$geoNote : ''), __LINE__, __FILE__, self::$module, $serviceId);
        chatNotify($label.' canceled but its scrub cleanup did not finish ('.$deleted.' removed; '.implode('; ', $failed).')'.($geoNote !== '' ? '; also '.$geoNote : '').' - please remove what is left by hand', 'notifications');
        return false;
    }

    /**
     * Lists one kind of id-keyed scrub entry for the ip and deletes each one that names it.
     * A refused delete is added to $failed, a mismatched entry to $skipped. An exception
     * from the scrub api is not caught: cleanupScrub() stops on it.
     *
     * @param object|string $scrub
     * @param string|null   $listing set to a short form of the listing when it was not a list
     * @return bool false when the listing was not a list (nothing was deleted)
     */
    private static function deleteListed($scrub, $kind, $listMethod, $deleteMethod, $destinationField, $bpf, &$deleted, array &$failed, array &$skipped, &$listing)
    {
        $list = call_user_func([$scrub, $listMethod], $bpf);
        $rows = self::listedRows($list);
        if ($rows === null) {
            $listing = substr((string) json_encode($list), 0, 200);
            return false;
        }
        foreach ($rows as $row) {
            $id = isset($row['id']) && is_scalar($row['id']) ? (int) $row['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $destination = isset($row[$destinationField]) && is_scalar($row[$destinationField]) ? (string) $row[$destinationField] : '';
            if ($destination !== (string) $bpf) {
                $skipped[] = $kind.' '.$id.' ('.$destinationField.' '.json_encode($destination).')';
                continue;
            }
            $response = call_user_func([$scrub, $deleteMethod], $id);
            if (self::deleteOk($response)) {
                $deleted++;
            } else {
                $failed[] = $kind.' '.$id.': '.json_encode($response);
            }
        }
        return true;
    }

    /**
     * Lists the filters for the ip and deletes each one that names it. An exception
     * from the scrub api is not caught: cleanupScrub() stops on it.
     *
     * @param object|string $scrub
     * @param string|null   $listing set to a short form of the listing when it was not a list
     * @return bool false when the listing was not a list (nothing was deleted)
     */
    private static function deleteFilters($scrub, $bpf, &$deleted, array &$failed, array &$skipped, &$listing)
    {
        $list = call_user_func([$scrub, 'filterListAll'], ['daddr' => $bpf]);
        $rows = self::listedRows($list);
        if ($rows === null) {
            $listing = substr((string) json_encode($list), 0, 200);
            return false;
        }
        foreach ($rows as $row) {
            $name = isset($row['filter_name']) && is_scalar($row['filter_name']) ? (string) $row['filter_name'] : '';
            $daddr = isset($row['daddr']) && is_scalar($row['daddr']) ? trim((string) $row['daddr']) : '';
            $dest = isset($row['dest']) && is_scalar($row['dest']) ? trim((string) $row['dest']) : (isset($row['filtered_port']) && is_scalar($row['filtered_port']) ? trim((string) $row['filtered_port']) : '');
            $describe = 'filter '.json_encode($name).' port '.json_encode($dest);
            if ($daddr !== (string) $bpf) {
                $skipped[] = $describe.' (daddr '.json_encode($daddr).')';
                continue;
            }
            $type = self::filterRouteName($name);
            if ($type === '') {
                // never sent to a sibling route (fivem_strict is not fivem): staff remove it by hand
                $failed[] = $describe.': filter type not supported by the scrub api, remove it by hand';
                continue;
            }
            if (!ctype_digit($dest)) {
                $failed[] = $describe.': unknown filter type or port';
                continue;
            }
            // the request the client filters page sends for the same delete
            $request = $type === 'synproxy'
                ? ['filtered_ip' => (int) $bpf, 'filtered_port' => (int) $dest]
                : ['daddr' => (int) $bpf, 'dest' => (int) $dest];
            $response = call_user_func([$scrub, 'filterDeleteByType'], $type, $request);
            if (self::deleteOk($response)) {
                $deleted++;
            } else {
                $failed[] = $describe.': '.json_encode($response);
            }
        }
        return true;
    }

    /**
     * Listed filter_name => api route (/filters/{route}/delete). Kept identical to core's
     * Scrub::FILTER_ROUTES (core's ScrubFilterRoutesTest compares the two).
     *
     * The 13 routes interserver/Scrub registers are their own names. The aliases are
     * only the listed names checked against the live api on 2026-11-06 (the row shows
     * up in that route's /list for its ip: ue5, raknet_udp, openvpn_udp) and the ones
     * the client filters page has always mapped by hand (sip, adaptive, cs2, a2s_udp;
     * udp_adaptive, cs2_udp and a2s are not served by the api today, so those deletes
     * get its "404 page not found" and are reported). Every other name, a
     * <route>_<suffix> such as fivem_strict included, has no route: the api answers
     * "ok" to a delete on any registered route, so sending it to a sibling route would
     * count a filter as removed that stays (or remove the sibling's filter on that port).
     */
    private const FILTER_ROUTES = [
        'openvpn' => 'openvpn',
        'wireguard' => 'wireguard',
        'dns' => 'dns',
        'raknet' => 'raknet',
        'scpsl' => 'scpsl',
        'genericsip' => 'genericsip',
        'fivem' => 'fivem',
        'litenetlib' => 'litenetlib',
        'sourcegengine' => 'sourcegengine',
        'arma3reforger' => 'arma3reforger',
        'conanexiles' => 'conanexiles',
        'unrealengine5' => 'unrealengine5',
        'synproxy' => 'synproxy',
        'ue5' => 'unrealengine5',
        'raknet_udp' => 'raknet',
        'openvpn_udp' => 'openvpn',
        'sip' => 'genericsip',
        'sip_udp' => 'genericsip',
        'adaptive' => 'udp_adaptive',
        'adaptive_udp' => 'udp_adaptive',
        'udp_adaptive' => 'udp_adaptive',
        'cs2' => 'cs2_udp',
        'cs2_udp' => 'cs2_udp',
        'a2s_udp' => 'a2s',
    ];

    /**
     * The delete route of a listed filter_name, the same explicit mapping as core's
     * Scrub::filterRouteName(): '' for a name with no route (see FILTER_ROUTES).
     *
     * @param mixed $name
     * @return string
     */
    private static function filterRouteName($name)
    {
        if (!is_string($name) || preg_match('/^[a-z0-9_]{1,64}\z/', $name) !== 1) {
            return '';
        }
        return self::FILTER_ROUTES[$name] ?? '';
    }

    /**
     * The rows of a scrub listing: [] for an empty answer, null for anything that is
     * not a list of entries (an error object, a "404 page not found" page).
     *
     * @param mixed $list
     * @return array|null
     */
    private static function listedRows($list)
    {
        if ($list === null || $list === []) {
            return [];
        }
        if (!is_array($list) || !isset($list[0])) {
            return null;
        }
        foreach ($list as $row) {
            if (!is_array($row)) {
                return null;
            }
        }
        return $list;
    }

    /**
     * Whether a scrub delete call succeeded: a bare "ok", the entry back (its id), or a success/status flag.
     *
     * @param mixed $response
     * @return bool
     */
    private static function deleteOk($response)
    {
        if (!is_array($response)) {
            return false;
        }
        if (isset($response['id'])) {
            // a firewall list delete may answer with the rule itself (ViewScrubIp::listResponseOk())
            return true;
        }
        if (isset($response[0]) && is_string($response[0]) && strtolower(trim($response[0])) === 'ok') {
            return true;
        }
        return !empty($response['success']) || (isset($response['status']) && in_array(strtolower((string) $response['status']), ['ok', 'success'], true));
    }

    /**
     * An IPv4 address in the byte order the scrub api keys its entries by
     * (uint_ip_to_bpf(): the octets reversed, as an unsigned int).
     *
     * @param string $ip a valid IPv4 address
     * @return int
     */
    private static function bpfIp($ip)
    {
        return (int) sprintf('%u', ip2long(implode('.', array_reverse(explode('.', $ip)))));
    }

    /**
     * @param \Symfony\Component\EventDispatcher\GenericEvent $event
     */
    public static function loadProcessing(GenericEvent $event)
    {
        /**
         * @var \ServiceHandler $service
         */
        $service = $event->getSubject();
        $service->setModule(self::$module)
            ->setEnable(function ($service) {
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                $serviceTypes = run_event('get_service_types', false, self::$module);
                myadmin_log(self::$module, 'info', self::$name.' Activation', __LINE__, __FILE__, self::$module, $serviceInfo[$settings['PREFIX'].'_id']);
                $getRegion = get_ip_region($serviceInfo[$settings['PREFIX'].'_ip']);
                $wanguard = Wanguard::getAnnouncementByIp($serviceInfo[$settings['PREFIX'].'_ip'], $getRegion['region_id'] ?? 2);
                if (!empty($wanguard) && $wanguard['status'] == 'Active') {
                    $response = [
                        'status' => 201,
                        'response' => json_encode(["href" => "/wanguard-api/v1/bgp_announcements/{$wanguard['announcement_id']}"])
                    ];
                    myadmin_log('myadmin', 'info', 'Scrub IP exsiting bgp_announcement Id - '.$wanguard['announcement_id'], __LINE__, __FILE__);
                } else {
                    $type = Wanguard::getConnectorId($getRegion['region_id']);
                    $response = Wanguard::add($serviceInfo[$settings['PREFIX'].'_ip'], $getRegion['region_id'] ?? 2, $type, '', 'from my by ScrubIp Activation');
                    myadmin_log('myadmin', 'info', 'Scrub IP new bgp_announcement', __LINE__, __FILE__);
                }
                if ($response['status'] == 201) {
                	$extra = json_encode($response);
                    $class = '\\MyAdmin\\Orm\\'.get_orm_class_from_table($settings['TABLE']);
                    /** @var \MyAdmin\Orm\Product $class **/
                    $serviceClass = new $class();
                    $serviceClass->load_real($serviceInfo[$settings['PREFIX'].'_id']);
                    $serviceClass->setStatus('active')->setExtra($extra)->save();
	                myadmin_log('myadmin', 'info', 'Scrub IP Activated. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
	            } else {
	            	myadmin_log('myadmin', 'info', 'Unable to activate Scrub IP. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
                    myadmin_log('myadmin', 'debug', 'ScrubIP Response - '.json_encode($response), __LINE__, __FILE__);
	            }
            })->setReactivate(function ($service) {
            	$serviceInfo = $service->getServiceInfo();
                $serviceTypes = run_event('get_service_types', false, self::$module);
                $settings = get_module_settings(self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('SCRUB_IPS')) {
                    $getRegion = get_ip_region($serviceInfo[$settings['PREFIX'].'_ip']);
                    $wanguard = Wanguard::getAnnouncementByIp($serviceInfo[$settings['PREFIX'].'_ip'], $getRegion['region_id'] ?? 2);
                    if (!empty($wanguard) && $wanguard['status'] == 'Active') {
                        $response = [
                            'status' => 201,
                            'response' => json_encode(["href" => "/wanguard-api/v1/bgp_announcements/{$wanguard['announcement_id']}"])
                        ];
                        myadmin_log('myadmin', 'info', 'Scrub IP exsiting bgp_announcement Id - '.$wanguard['announcement_id'], __LINE__, __FILE__);
                    } else {
                        $type = Wanguard::getConnectorId($getRegion['region_id']);
                        $response = Wanguard::add($serviceInfo[$settings['PREFIX'].'_ip'], $getRegion['region_id'] ?? 2, $type, '', 'from my by ScrubIp Reactivation');
                        myadmin_log('myadmin', 'info', 'Scrub IP new bgp_announcement', __LINE__, __FILE__);
                    }
                	if ($response['status'] == 201) {
                		$extra = json_encode($response);
                        $class = '\\MyAdmin\\Orm\\'.get_orm_class_from_table($settings['TABLE']);
                        /** @var \MyAdmin\Orm\Product $class **/
                        $serviceClass = new $class();
                        $serviceClass->load_real($serviceInfo[$settings['PREFIX'].'_id']);
                        $serviceClass->setStatus('active')->setExtra($extra)->save();
		                \MyAdmin\App::history()->add($settings['TABLE'], 'change_status', 'active', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
		                $smarty = new \TFSmarty();
		                $smarty->assign('backup_name', $serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_name']);
		                $email = $smarty->fetch('email/admin/backup_reactivated.tpl');
		                $subject = $serviceInfo[$settings['TITLE_FIELD']].' '.$serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_name'].' '.$settings['TBLNAME'].' Reactivated';
		                (new \MyAdmin\Mail())->adminMail($subject, $email, false, 'admin/backup_reactivated.tpl');
		                myadmin_log('myadmin', 'info', 'Scrub IP re-activated. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
		            } else {
		            	myadmin_log('myadmin', 'info', 'Unable to re-activate Scrub IP. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
                        myadmin_log('myadmin', 'debug', 'ScrubIP Response - '.json_encode($response), __LINE__, __FILE__);
		            }
	            }
            })->setDisable(function ($service) {
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                $serviceTypes = run_event('get_service_types', false, self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('SCRUB_IPS')) {
                	//Do nothing for now
                }
            })->setTerminate(function ($service) {
                $serviceInfo = $service->getServiceInfo();
                $settings = get_module_settings(self::$module);
                $serviceTypes = run_event('get_service_types', false, self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('SCRUB_IPS')) {
                    $class = '\\MyAdmin\\Orm\\'.get_orm_class_from_table($settings['TABLE']);
                    /** @var \MyAdmin\Orm\Product $class **/
                    $serviceClass = new $class();
                    $serviceClass->load_real($serviceInfo[$settings['PREFIX'].'_id']);
                    $serviceClass->setStatus('canceled')->save();
                    \MyAdmin\App::history()->add($settings['TABLE'], 'change_status', 'canceled', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
                    if (!empty($serviceInfo[$settings['PREFIX'].'_extra'])) {
                    	$tmp = json_decode($serviceInfo[$settings['PREFIX'].'_extra'], true);
                    	$tmp1 = json_decode($tmp['response'], true);
                    	$w_id = str_replace('/wanguard-api/v1/bgp_announcements/', '', $tmp1['href']);
                    	if (intval($w_id) > 0) {
                            $getRegion = get_ip_region($serviceInfo[$settings['PREFIX'].'_ip']);
                            $deleted = Wanguard::delete($w_id, $getRegion['region_id'] ?? 2);
                    		if ($deleted['status'] != 200) {
		            			myadmin_log('myadmin', 'info', 'Unable to delete wangaurdID -'.$w_id.' Scrub IP. ServiceId - '.$serviceClass->getId(), __LINE__, __FILE__);
		            			$smarty = new \TFSmarty();
				                $smarty->assign('module', self::$module);
				                $smarty->assign('id', $serviceClass->getId());
				                $smarty->assign('settings', $settings);
				                $email = $smarty->fetch('email/admin/deactivate_error.tpl');
				                $subject = 'ScrubIps Deactivation error ID';
				                (new \MyAdmin\Mail())->adminMail($subject, $email, false, 'admin/deactivate_error.tpl');
            				}
                    	}
                    }
                    // the service is finished: its rules and filters go unless another service holds the ip
                    self::cleanupReleasedIp($serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_ip']);
                }
            })->register();
    }

    /**
     * @param \Symfony\Component\EventDispatcher\GenericEvent $event
     */
    public static function getSettings(GenericEvent $event)
    {
        /**
         * @var \MyAdmin\Settings $settings
         **/
        $settings = $event->getSubject();
        $settings->setTarget('global');
        $settings->add_text_setting(self::$module, _('SCRUB_ENDPOINT'), 'scrub_endpoint', _('Scrub URL'), _('Scrub URL'), $settings->get_setting('SCRUB_ENDPOINT'));
        $settings->add_text_setting(self::$module, _('SCRUBLOGS'), 'scrublogs_ip', _('Scrub Logs IP'), _('Scrub Logs IP'), $settings->get_setting('SCRUBLOGS_IP'));
        $settings->add_text_setting(self::$module, _('SCRUBLOGS'), 'scrublogs_port', _('Scrub Logs Port'), _('Scrub Logs Port'), $settings->get_setting('SCRUBLOGS_PORT'));
        $settings->add_text_setting(self::$module, _('SCRUBLOGS'), 'scrublogs_user', _('Scrub Logs User'), _('Scrub Logs User'), $settings->get_setting('SCRUBLOGS_USER'));
        $settings->add_password_setting(self::$module, _('SCRUBLOGS'), 'scrublogs_password', _('Scrub Logs Password'), _('Scrub Logs Password'), $settings->get_setting('SCRUBLOGS_PASSWORD'));
    }
}