<?php

declare(strict_types=1);

namespace Ganesh\MyadminScrubIps\Tests\Support;

/**
 * Stands in for \MyAdmin\scrub_ips\Scrub (Plugin::$scrubFactory): answers the
 * listings with the configured rows and records every call.
 *
 * A listing configured as a \Throwable is thrown, anything else is returned as is,
 * so a test can hand back an error object or the scrub api's "404 page not found".
 */
final class ScrubDouble
{
    /** @var array<int, array<int, mixed>> every call, as [method, ...args] */
    public $calls = [];

    /** @var array<string, mixed> answer of each listing method */
    public $lists = [
        'firewallListByIp' => [],
        'geoFirewallListByIp' => [],
        'firewallListsByIp' => [],
        'filterListAll' => [],
    ];

    /** @var mixed answer of every delete; a \Throwable is thrown instead */
    public $deleteResponse = ['ok'];

    /**
     * @param array<string, mixed> $lists
     */
    public function __construct(array $lists = [])
    {
        $this->lists = array_merge($this->lists, $lists);
    }

    public function firewallListByIp($ip)
    {
        return $this->listing('firewallListByIp', $ip);
    }

    public function geoFirewallListByIp($ip)
    {
        return $this->listing('geoFirewallListByIp', $ip);
    }

    public function firewallListsByIp($ip)
    {
        return $this->listing('firewallListsByIp', $ip);
    }

    public function filterListAll($request)
    {
        return $this->listing('filterListAll', $request);
    }

    public function firewallDelete(int $id)
    {
        $this->calls[] = ['firewallDelete', $id];
        return $this->deleteAnswer();
    }

    public function geoFirewallDelete(int $id)
    {
        $this->calls[] = ['geoFirewallDelete', $id];
        return $this->deleteAnswer();
    }

    public function firewallListDelete(int $id)
    {
        $this->calls[] = ['firewallListDelete', $id];
        return $this->deleteAnswer();
    }

    public function filterDeleteByType(string $type, array $request)
    {
        $this->calls[] = ['filterDeleteByType', $type, $request];
        return $this->deleteAnswer();
    }

    /**
     * Calls whose method starts with the given name.
     *
     * @return array<int, array<int, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static function (array $call) use ($method) {
            return $call[0] === $method;
        }));
    }

    /**
     * @return mixed
     */
    private function deleteAnswer()
    {
        if ($this->deleteResponse instanceof \Throwable) {
            throw $this->deleteResponse;
        }
        return $this->deleteResponse;
    }

    /**
     * @param mixed $argument
     * @return mixed
     */
    private function listing(string $method, $argument)
    {
        $this->calls[] = [$method, $argument];
        $answer = $this->lists[$method];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        return $answer;
    }
}
