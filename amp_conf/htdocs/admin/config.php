<?php /* $Id$ */
//Copyright (C) 2004 Coalescent Systems Inc. (info@coalescentsystems.ca)
//
//This program is free software; you can redistribute it and/or
//modify it under the terms of the GNU General Public License
//as published by the Free Software Foundation; either version 2
//of the License, or (at your option) any later version.
//
//This program is distributed in the hope that it will be useful,
//but WITHOUT ANY WARRANTY; without even the implied warranty of
//MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//GNU General Public License for more details.

$title="Asterisk Management Portal";
$message="Setup";

require_once('functions.php');

// get settings
$amp_conf = parse_amportal_conf("/etc/amportal.conf");

// start session
session_start();

// connect to database
require_once('common/db_connect.php'); //PEAR must be installed

//  unset server vars if we are logged out
if (isset($_SESSION["logout"])) {
	unset($_SERVER["PHP_AUTH_USER"]);
	unset($_SERVER["PHP_AUTH_PW"]);
	unset($_SESSION["logout"]);
}

switch ($amp_conf["AUTHTYPE"]) {
	case "database":
		if (!isset($_SERVER["PHP_AUTH_USER"])) {
			header("WWW-Authenticate: Basic realm=\"AMPortal\"");
			header("HTTP/1.0 401 Unauthorized");
			echo "You are not authorized to use this resource<br>";
			echo "<a href=index.php?action=logout>Go Back</a>";
			exit;
		} else {
			$_SESSION["user"] = new ampuser($_SERVER["PHP_AUTH_USER"]);
			if (!$_SESSION["user"]->checkPassword($_SERVER["PHP_AUTH_PW"])) {
			
				// one last chance -- check admin user
				if (($_SERVER["PHP_AUTH_USER"] == $amp_conf["AMPDBUSER"]) && ($_SERVER["PHP_AUTH_PW"] == $amp_conf["AMPDBPASS"])) {
					// set admin access
					$_SESSION["user"]->setAdmin();
				} else {
					header("HTTP/1.0 401 Unauthorized");
					echo "You are not authorized to use this resource<br>";
					echo "<a href=index.php?action=logout>Go Back</a>";
					exit;
				}
			}
		}
	break;
	case "http":
		
	break;
	default: 
		if (!isset($_SESSION["user"])) {
			$_SESSION["user"] = new ampuser($amp_conf["AMPDBUSER"]);
		}
		$_SESSION["user"]->setAdmin();
	break;
}

//make sure our tables are there
sipexists();
iaxexists();
zapexists();

// setup html
include 'header.php';

if (isset($_REQUEST['display'])) {
	$display=$_REQUEST['display'];
}

// setup menu 
$amp_sections = array(
		9=>"Incoming Calls",
		3=>"Extensions",
		4=>"Ring Groups",
		11=>"Queues",
		2=>"Digital Receptionist",
		6=>"Trunks",
		8=>"Outbound Routing",
		7=>"DID Routes",
		1=>"On Hold Music",
		12=>"System Recordings",
		5=>"General Settings",
		99=>"Apply Changes Bar"
	);
	
// only show AMP Users if they have authtype set approiately
if (isset($amp_conf["AUTHTYPE"]) && ($amp_conf["AUTHTYPE"] != "none")) {
	$amp_sections[10] = "AMP Users";
}


// show menu
echo "<div class=\"nav\">";

foreach ($amp_sections as $key=>$value) {

	// check access
	if ($_SESSION["user"]->checkSection($key)) {
		if ($key != 99) {
			echo "<li><a id=\"".(($display==$key) ? 'current':'')."\" href=\"config.php?display=".$key."\">".$value."</a></li>";
		}
	} else {
		// they don't have access to this, remove it completely
		unset($amp_sections[$key]);
	}
}
	
echo "</div>";

?>

<div class="content">

<?php 
// check access
if (!empty($display) && !isset($amp_sections[$display])) {
	$display = "noaccess";
}

// show the approiate page
switch($display) {
	default:
		echo "<p>Welcome to AMP</p>";
		echo str_repeat("<br />", 12);
	break;
	case 'noaccess':
		echo "<h2>Not found</h2>";
		echo "<p>The section you requested does not exist or you do not have access to it.</p>";
		echo str_repeat("<br />", 10);
	break;
	case '9':
		echo "<h2>Incoming Calls</h2>";
		include 'incoming.php';
	break;
	case '1':
		include 'music.php';
	break;
	case '2':
		echo "<h2>Digital Receptionist</h2>";
		// The Digital Receptionist code is a rat's nest.  If you are planning on making significant modifications, just re-write from scratch.
		//if menu_id is being empty, or if we are requesting delete, just use ivr_action.php
		if ((empty($_REQUEST['menu_id'])) || ($_REQUEST['ivr_action'] == 'delete'))
			include 'ivr_action.php'; 
		else
			include 'ivr.php'; //wizard to create/edit a menu
	break;
	case '3':
		include 'extensions.php';
	break;
	case '4':
		include 'callgroups.php';
	break;
	case '11':
		include 'queues.php';
	break;
	case '5':
		echo "<h2>General Settings</h2>";
		include 'general.php';
	break;
	case '6':
		include 'trunks.php';
	break;
	case '7':
		include 'did.php';
	break;
	case '8':
		include 'routing.php';
	break;
	case '12':
		include 'recordings.php';
	break;
	case '10':
		include 'ampusers.php';
	break;
}
?>

</div>
<?php echo str_repeat("<br />", 8);?>
<?php include 'footer.php' ?>
