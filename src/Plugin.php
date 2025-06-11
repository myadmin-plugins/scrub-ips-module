<?php

namespace Ganesh\MyadminScrubIps;

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
            if (!empty($serviceClass->getExtra())) {
            	$tmp = json_decode($serviceClass->getExtra(), true);
            	$tmp1 = json_decode($tmp['response'], true);
            	$w_id = str_replace('/wanguard-api/v1/bgp_announcements/', '', $tmp1['href']);
            	if (intval($w_id) > 0) {
            		function_requirements('class.Wanguard');
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
            		}
            	}
            }
        }
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
                function_requirements('class.Wanguard');
                $response = Wanguard::add($serviceInfo[$settings['PREFIX'].'_ip']);
                if ($response['status'] == 201) {
                	$extra = json_encode($response, true);
                	$db = get_module_db(self::$module);
	                $db->query("UPDATE {$settings['TABLE']} SET {$settings['PREFIX']}_status='active',{$settings['PREFIX']}_extra = '$extra' WHERE {$settings['PREFIX']}_id='".$serviceInfo[$settings['PREFIX'].'_id']."'", __LINE__, __FILE__);
	                myadmin_log('myadmin', 'info', 'Scrub IP Activated. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
	            } else {
	            	myadmin_log('myadmin', 'info', 'Unable to activate Scrub IP. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
	            }
            })->setReactivate(function ($service) {
            	$serviceInfo = $service->getServiceInfo();
                $serviceTypes = run_event('get_service_types', false, self::$module);
                $settings = get_module_settings(self::$module);
                if ($serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_type'] == get_service_define('SCRUB_IPS')) {
                	function_requirements('class.Wanguard');
                	$response = Wanguard::add($serviceInfo[$settings['PREFIX'].'_ip']);
                	if ($response['status'] == 201) {
                		$extra = json_encode($response, true);
		                $db = get_module_db(self::$module);
		                $db->query("UPDATE {$settings['TABLE']} SET {$settings['PREFIX']}_status='active',{$settings['PREFIX']}_extra = '$extra' WHERE {$settings['PREFIX']}_id='".$serviceInfo[$settings['PREFIX'].'_id']."'", __LINE__, __FILE__);
		                $GLOBALS['tf']->history->add($settings['TABLE'], 'change_status', 'active', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
		                $smarty = new \TFSmarty();
		                $smarty->assign('backup_name', $serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_name']);
		                $email = $smarty->fetch('email/admin/backup_reactivated.tpl');
		                $subject = $serviceInfo[$settings['TITLE_FIELD']].' '.$serviceTypes[$serviceInfo[$settings['PREFIX'].'_type']]['services_name'].' '.$settings['TBLNAME'].' Reactivated';
		                (new \MyAdmin\Mail())->adminMail($subject, $email, false, 'admin/backup_reactivated.tpl');
		                myadmin_log('myadmin', 'info', 'Scrub IP re-activated. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
		            } else {
		            	myadmin_log('myadmin', 'info', 'Unable to re-activate Scrub IP. ServiceId - '.$serviceInfo[$settings['PREFIX'].'_id'], __LINE__, __FILE__);
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
                    $db = get_module_db(self::$module);
                    $serviceClass->setStatus('canceled')->save();
                    $GLOBALS['tf']->history->add($settings['TABLE'], 'change_status', 'canceled', $serviceInfo[$settings['PREFIX'].'_id'], $serviceInfo[$settings['PREFIX'].'_custid']);
                    if (!empty($serviceInfo[$settings['PREFIX'].'_extra'])) {
                    	$tmp = json_decode($serviceInfo[$settings['PREFIX'].'_extra'], true);
                    	$tmp1 = json_decode($tmp['response'], true);
                    	$w_id = str_replace('/wanguard-api/v1/bgp_announcements/', '', $tmp1['href']);
                    	if (intval($w_id) > 0) {
                    		function_requirements('class.Wanguard');
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
            				}
                    	}
                    }
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
    }
}