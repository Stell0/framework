#!/usr/bin/env php
<?php
//include bootstrap
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2013-2015 Schmooze Com Inc.
//
$bootstrap_settings['freepbx_auth'] = false;
include '/etc/freepbx.conf';
// Define the notification class for logging to the dashboard
//
$nt = notifications::create($db);

// Check to see if email should be sent
//

$cm =& cronmanager::create($db);

$cm->run_jobs();
//If we have sysadmin installed
$from_email = get_current_user() . '@' . gethostname();
if(function_exists('sysadmin_get_storage_email')){
	$emails = sysadmin_get_storage_email();
	//Check that what we got back above is a email address
	if(!empty($emails['fromemail']) && filter_var($emails['fromemail'],FILTER_VALIDATE_EMAIL)){
		//Fallback address
		$from_email = $emails['fromemail'];
	}
}

//Send email with our mail class
function cron_scheduler_send_message($to,$from,$subject,$message){
	$em = new \CI_Email();
	$em->from($from);
	$em->to($to);
	$em->subject($subject);
	$em->message($message);
	return $em->send();
}
$brand = $amp_conf['DASHBOARD_FREEPBX_BRAND']?$amp_conf['DASHBOARD_FREEPBX_BRAND']:'FreePBX';
$email = $cm->get_email();
if ($email) {
	$text = "";
	$mid = $cm->get_machineid();
	$htext  = sprintf(_("This notification was generated by the %s Server identified as '%s'."),$brand,$mid)."\n";
	$htext .= _("You may change this designation in the module admin page, by clicking on the alert icon in the top right hand corner of the page.")."\n";

	// clear email flag
	$nt->delete('freepbx', 'NOEMAIL');

	//list_signature_unsigned

	// set to false, if no updates are needed then it will not be
	// set to true and no email will go out even though the hash
	// may have changed.
	//
	if(FreePBX::Config()->get('SEND_UNSIGNED_EMAILS_NOTIFICATIONS')) {
		$send_email = false;

		$unsigned = $nt->list_signature_unsigned();
		$text = '';
		if (count($unsigned)) {
			$send_email = true;
			$text = $htext;
			$text .= "\n" . _("UNSIGNED MODULES NOTICE:")."\n\n";
			foreach ($unsigned as $item) {
				$text .= $item['display_text'].":\n";
				$text .= $item['extended_text']."\n";
			}
		}
		$text .= "\n\n";

		if ($send_email && (! $cm->check_hash('update_sigemail', $text))) {
			$cm->save_hash('update_sigemail', $text);
			if (cron_scheduler_send_message($email, $from_email, sprintf(_("%s: New Unsigned Modules Notifications (%s)"),$brand, $mid), $text)) {
				$nt->delete('freepbx', 'SIGEMAILFAIL');
			} else {
				$nt->add_error('freepbx', 'SIGEMAILFAIL', _('Failed to send unsigned modules notification email'), sprintf(_('An attempt to send email to: %s with unsigned modules notifications failed'),$email));
			}
		}
	}

	$text = "";
	$send_email = false;

	$security = $nt->list_security();
	if (count($security)) {
		$send_email = true;
		$text = $htext . "\n";
		$text .= _("SECURITY NOTICE:")."\n\n";
		foreach ($security as $item) {
			$text .= $item['display_text'].":\n";
			$text .= $item['extended_text']."\n";
		}
	}
	$text .= "\n\n";

	if ($send_email && (! $cm->check_hash('update_semail', $text))) {
		$cm->save_hash('update_semail', $text);
		if (cron_scheduler_send_message($email, $from_email, sprintf(_("%s: New Security Notifications (%s)"),$brand, $mid), $text)) {
			$nt->delete('freepbx', 'SEMAILFAIL');
		} else {
			$nt->add_error('freepbx', 'SEMAILFAIL', _('Failed to send security notification email'), sprintf(_('An attempt to send email to: %s with security notifications failed'),$email));
		}
	}

	$text = "";
	$send_email = false;

	$updates = $nt->list_update();
	if (count($updates)) {
		$send_email = true;
		$text = $htext . "\n";
		$text .= _("UPDATE NOTICE:")."\n\n";
		foreach ($updates as $item) {
			$text .= $item['display_text']."\n";
			$text .= $item['extended_text']."\n\n";
		}
	}

	if ($send_email && (! $cm->check_hash('update_email', $text))) {
		$cm->save_hash('update_email', $text);
		if (cron_scheduler_send_message($email, $from_email, sprintf(_("%s: New Online Updates Available (%s)"),$brand,$mid), $text)) {
			$nt->delete('freepbx', 'EMAILFAIL');
		} else {
			$nt->add_error('freepbx', 'EMAILFAIL', _('Failed to send online update email'), sprintf(_('An attempt to send email to: %s with online update status failed'),$email));
		}
	}
} else {
	$nt->add_notice('freepbx', 'NOEMAIL', _('No email address for online update checks'), _('You are automatically checking for online updates nightly but you have no email address setup to send the results. This can be set in Module Admin. They will continue to show up here.'), 'config.php?display=modules#email', 'PASSIVE', false);
}
?>
