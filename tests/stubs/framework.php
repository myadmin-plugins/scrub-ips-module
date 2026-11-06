<?php

declare(strict_types=1);

/**
 * Stand-ins for the MyAdmin framework services that src/Plugin.php calls out to.
 *
 * MyAdmin itself is not a dependency of this package, so these stubs are what make
 * it possible to register the plugin's hooks on a real event dispatcher and invoke
 * them inside the test suite. Everything observable is recorded on FrameworkSpy.
 */

namespace MyAdmin {
    use Ganesh\MyadminScrubIps\Tests\Support\FrameworkSpy;

    class App
    {
        /**
         * The service-type id get_service_define('SCRUB_IPS') resolves to in tests.
         */
        public const SCRUB_IPS_SERVICE_TYPE = 905;

        public static function getServiceDefine($service)
        {
            return $service === 'SCRUB_IPS' ? self::SCRUB_IPS_SERVICE_TYPE : -1;
        }

        public static function history()
        {
            return new class () {
                public function add(...$args)
                {
                    FrameworkSpy::$history[] = $args;
                }
            };
        }
    }

    class Mail
    {
        public function adminMail($subject, ...$args)
        {
            FrameworkSpy::$adminMails[] = $subject;
            return true;
        }
    }
}

namespace MyAdmin\Orm {
    use Ganesh\MyadminScrubIps\Tests\Support\FrameworkSpy;

    /**
     * The ORM row the closures load (get_orm_class_from_table() below names it).
     */
    class ScrubIpsOrmDouble
    {
        public function load_real($id)
        {
            FrameworkSpy::$orm[] = ['load_real', $id];
            return true;
        }

        public function getId()
        {
            return 4242;
        }

        public function setStatus($status)
        {
            FrameworkSpy::$orm[] = ['setStatus', $status];
            return $this;
        }

        public function setExtra($extra)
        {
            FrameworkSpy::$orm[] = ['setExtra', $extra];
            return $this;
        }

        public function save()
        {
            FrameworkSpy::$orm[] = ['save'];
            return true;
        }
    }
}

namespace MyAdmin\scrub_ips {
    use Ganesh\MyadminScrubIps\Tests\Support\FrameworkSpy;

    /**
     * Records the BGP announcement calls instead of talking to Wanguard.
     */
    class Wanguard
    {
        /** @var int status delete() answers with */
        public static $deleteStatus = 200;

        public static function delete($id, $region = 2)
        {
            FrameworkSpy::$wanguard[] = ['delete', $id, $region];
            return ['status' => self::$deleteStatus, 'response' => ''];
        }
    }
}

namespace {
    use Ganesh\MyadminScrubIps\Tests\Support\FrameworkSpy;

    if (!class_exists('TFSmarty', false)) {
        class TFSmarty
        {
            public function assign(...$args)
            {
            }

            public function fetch($template)
            {
                return 'rendered ' . $template;
            }
        }
    }

    if (!function_exists('myadmin_log')) {
        function myadmin_log(...$args)
        {
            FrameworkSpy::$logs[] = $args;
        }
    }

    if (!function_exists('run_event')) {
        /**
         * Only 'get_service_types' is consumed by this plugin.
         */
        function run_event($event, $data = false, $module = '')
        {
            if ($event === 'get_service_types') {
                return FrameworkSpy::$serviceTypes;
            }
            return $data;
        }
    }

    if (!function_exists('chatNotify')) {
        function chatNotify(...$args)
        {
            FrameworkSpy::$notifications[] = $args;
        }
    }

    if (!function_exists('get_orm_class_from_table')) {
        function get_orm_class_from_table($table)
        {
            return 'ScrubIpsOrmDouble';
        }
    }

    if (!function_exists('get_ip_region')) {
        function get_ip_region($ip)
        {
            return ['region_id' => 2];
        }
    }

    // gettext is not guaranteed to be enabled on every CI leg.
    if (!function_exists('_')) {
        function _($string)
        {
            return $string;
        }
    }
}
