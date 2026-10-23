<?php
/** Background metadata only: no order data, checkout HTTP or implicit opt-in. */
class Wc_Smart_Cod_Installation_Sync {
 const EVENT = 'wsc_sync_installation_status';
 const STATE = 'wsc_installation_sync_state';
 const LOCK = 'wsc_installation_sync_lock';
 public static function register() {
  add_action('init',array(__CLASS__,'queue'),25);
  add_action('update_option_woocommerce_cod_settings',array(__CLASS__,'queue'),30,0);
  add_action('add_option_woocommerce_cod_settings',array(__CLASS__,'queue'),30,0);
  add_action(self::EVENT,array(__CLASS__,'send'));
 }
 public static function queue() {
  $settings=get_option('woocommerce_cod_settings',array());
  $enabled=is_array($settings)&&isset($settings['enable_smart_cod_ai_data_sharing'])&&'yes'===$settings['enable_smart_cod_ai_data_sharing'];
  $state=get_option(self::STATE);
  $version=defined('SMART_COD_VER')?SMART_COD_VER:'';
  $domain=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
  $base=get_option('woocommerce_default_country','');$country=strtoupper(substr((string)$base,0,2));
  // Never register somebody who has never opted in. Existing keys alone are not consent.
  if (!$enabled&&!is_array($state)&&!is_array(get_option('wsc_smart_cod_ai_enrolled'))) return;
  if (is_array($state)&&$state['enabled']===$enabled&&$state['version']===$version&&$state['domain']===$domain&&$state['country']===$country) {
   if ($state['revision']!==$state['ack_revision']) self::schedule(60);
   return;
  }
  if (!self::lock()) { self::schedule(60); return; }
  try {
   $state=get_option(self::STATE);
   $version=defined('SMART_COD_VER')?SMART_COD_VER:'';
   $domain=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
   $base=get_option('woocommerce_default_country','');$country=strtoupper(substr((string)$base,0,2));
   if (!is_array($state)||$state['enabled']!==$enabled||$state['version']!==$version||$state['domain']!==$domain||$state['country']!==$country) {
    $state=array('enabled'=>$enabled,'version'=>$version,'domain'=>$domain,'country'=>$country,'revision'=>is_array($state)?$state['revision']+1:1,'ack_revision'=>is_array($state)?$state['ack_revision']:0,'attempts'=>0);
    update_option(self::STATE,$state,false);
    wp_clear_scheduled_hook(self::EVENT);
   }
   if ($state['revision']!==$state['ack_revision']) self::schedule(wp_rand(30,90));
  } finally { delete_option(self::LOCK); }
 }
 public static function send() {
  self::queue();$state=get_option(self::STATE);
  if (!is_array($state)||$state['revision']===$state['ack_revision']) return;
  if (!class_exists('Wc_Smart_Cod_Ai_Outbox')) require_once __DIR__.'/class-wc-smart-cod-ai-outbox.php';
  $ok=Wc_Smart_Cod_Ai_Outbox::sync_installation_registration();
  if (!self::lock()) { self::schedule(60); return; }
  try {
   $current=get_option(self::STATE);
   if (!is_array($current)||$current['revision']!==$state['revision']) { self::schedule(30); return; }
   if ($ok) { $current['ack_revision']=$state['revision'];$current['attempts']=0;wp_clear_scheduled_hook(self::EVENT); }
   else { $current['attempts']++;wp_clear_scheduled_hook(self::EVENT);self::schedule(min(21600,300*pow(2,min(6,$current['attempts']-1)))+wp_rand(0,60)); }
   update_option(self::STATE,$current,false);
  } finally { delete_option(self::LOCK); }
 }
 private static function schedule($delay) { if (!wp_next_scheduled(self::EVENT)) wp_schedule_single_event(time()+(int)$delay,self::EVENT); }
 private static function lock() {
  if ((int)get_option(self::LOCK,0)<time()-300) delete_option(self::LOCK);
  return add_option(self::LOCK,time(),' ',false);
 }
}
