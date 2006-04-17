#!/usr/bin/php -q
<?php 
//
// Copyright (C) 2003 Zac Sprackett <zsprackett-asterisk@sprackett.com>
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
// 
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// Amended by Coalescent Systems Inc. Sept, 2004
// to include support for DND, Call Waiting, and CF to external trunk
// info@coalescentsystems.ca
// 
// This script has been ported to PHP by Diego Iastrubni <diego.iastrubni@xorcom.com>

$config = parse_amportal_conf( "/etc/amportal.conf" );

require_once "phpagi.php";
require_once "phpagi-asmanager.php";

$debug = 4;

$ext="";      // Hash that will contain our list of extensions to call
$ext_hunt=""; // Hash that will contain our list of extensions to call used by huntgroup
$cidnum="";   // Caller ID Number for this call
$cidname="";  // Caller ID Name for this call
$timer="";    // Call timer for Dial command
$dialopts=""; // options for dialing
$rc="";       // Catch return code
$priority=""; // Next priority 
$rgmethod=""; // If Ring Group what ringing method was chosen
$dsarray = array(); // This will hold all the dial strings, used to check for duplicate extensions

$AGI = new AGI();
debug("Starting New Dialparties.agi", 0);

if ($debug >= 2) 
{
	foreach( $keys as $key=>$value)
	{
		debug("$key = $value" ,3);
		$AGI->verbose("$key = $value", 2);
	}
}

$priority = get_var( $AGI, "priority" ) + 1;
debug( "priority is $priority" );

// Caller ID info is stored in $request in AGI class, passed from Asterisk
$cidnum = $AGI->request['agi_callerid'];
$cidname = $AGI->request['agi_calleridname'];
debug("Caller ID name is '$cidname' number is '$cidnum'", 1);

$timer		= get_var( $AGI, "ARG1" );
$dialopts	= get_var( $AGI, "ARG2" );
$rgmethod	= get_var( $AGI, "RingGroupMethod" );
if (empty($timer))	$timer		= 0;
if (empty($dialopts))	$dialopts	= "";
if (empty($rgmethod))	$rgmethod	= "none";
debug("Methodology of ring is  '$rgmethod'", 1);

// Start with Arg Count set to 3 as two args are used
$arg_cnt = 3;
while( ($arg = get_var($AGI,"ARG". $arg_cnt)) )
{
	if ($arg == '-') 
	{  // not sure why, dialparties will get stuck in a loop if noresponse
		debug("get_variable got a \"noresponse\"!  Exiting",3);
		exit($arg_cnt);
	}
	
	$extarray = split( '-', $arg );
	foreach ( $extarray as $k )
	{
		$ext[] = $k;
		debug("Added extension $k to extension map", 3);
	}
	
	$arg_cnt++;
}

// Check for call forwarding first
// If call forward is enabled, we use chan_local
foreach( $ext as $k)
{
	$cf  = $AGI->database_get('CF',$k);
	$cf  = $cf['data'];
	if (strlen($cf)) 
	{
		// append a hash sign so we can send out on chan_local below.
		$k = $cf.'#';  
		debug("Extension $k has call forward set to $cf", 1);
	} 
	else 
	{
		debug("Extension $k cf is disabled", 3);
	}
}

// Now check for DND
foreach ( $ext as $k )
{
	//if ( !preg_match($k, "/\#/", $matches) )
	if ( (strpos($k,"#")==0) )
	{   
		// no point in doing if cf is enabled
		$dnd = $AGI->database_get('DND',$k);
		$dnd = $dnd['data'];
		if (strlen($dnd)) 
		{
			debug("Extension $k has do not disturb enabled", 1);
			unset($k);
			//PERL: delete $ext{$k};
		} 
		else 
		{
			debug("Extension $k do not disturb is disabled", 3);
		}
	}
}

// Main calling loop
$ds = '';
foreach ( $ext as $k )
{
	$extnum    = $k;
	$exthascw  = $AGI->database_get('CW', $extnum);// ? 1 : 0;
	$exthascw  = $exthascw['data']? 1:0;
	$extcfb    = $AGI->database_get('CFB', $extnum);//? 1 : 0;
	$extcfb    = $extcfb['data'];
	$exthascfb = (strlen($extcfb) > 0) ? 1 : 0;
	$extcfu    = $AGI->database_get('CFU', $extnum);// ? 1 : 0;
	$extcfu    = $extcfu['data'];
 	$exthascfu = (strlen($extcfu) > 0) ? 1 : 0;
	
	// Dump details in level 4
	debug("extnum: $extnum",4);
	debug("exthascw: $exthascw",4);
	debug("exthascfb: $exthascfb",4);
	debug("extcfb: $extcfb",4);
	debug("exthascfu: $exthascfu",4);
	debug("extcfu: $extcfu",4);
	
	// if CF is not in use
	if ( (strpos($k,"#")==0) )
	{
		// CW is not in use or CFB is in use on this extension, then we need to check!
		if ( ($exthascw == 0) || ($exthascfb == 1) || ($exthascfu == 1) )
		{
			// get ExtensionState: 0-idle; 1-busy; 4-unavail <--- these are unconfirmed
			$extstate = is_ext_avail($extnum);
	
			if ( ($exthascfu == 1) && ($extstate == 4) ) // Ext has CFU and is Unavailable
			{
				// If part of a ring group, then just do what CF does, otherwise needs to
				// drop back to dialplant with NOANSWER
				if ($rgmethod != '' && $rgmethod != 'none')
				{
					debug("Extension $extnum has call forward on no answer set and is unavailable and is part of a Ring Group forwarding to '$extcfu'",1);
						$extnum = $extcfu . '#';   # same method as the normal cf, i.e. send to Local
				}
				else 
				{
					debug("Extension $extnum has call forward on no answer set and is unavailable",1);
					$extnum = '';
					$AGI->set_variable('DIALSTATUS','NOANSWER');
				}
			}
			elseif ( ($exthascw == 0) || ($exthascfb == 1) ) 
			{	
				debug("Checking CW and CFB status for extension $extnum",3);
			
				if ($extstate > 0)
				{ // extension in use
					debug("Extension $extnum is not available to be called", 1);
					
					if ($exthascfb == 1) // extension in use
					{	// CFB is in use
						debug("Extension $extnum has call forward on busy set to $extcfb",1);
						$extnum = $extcfb . '#';   # same method as the normal cf, i.e. send to Local
					} 
					elseif ($exthascw == 0) 
					{	// CW not in use
						debug("Extension $extnum has call waiting disabled",1);
						$extnum = '';
						$AGI->set_variable('DIALSTATUS','BUSY');						
					} 
					else 
					{
						debug("Extension $extnum has call waiting enabled",1);
					}
				}
			}
			elseif ($extstate < 0)
			{	// -1 means couldn't read status usually due to missing HINT
				debug("ExtensionState for $extnum could not be read...assuming ok",3);
			} 
			else 
			{
				debug("Extension $extnum is available",1);
			}
		}
	}
	elseif ($exthascw == 1) 
	{	// just log the fact that CW enabled
		debug("Extension $extnum has call waiting enabled",1);
	}
	
	if ($extnum != '')
	{	// Still got an extension to be called?
		// check if we already have a dial string for this extension
		// if so, ignore it as it's pointless ringing it twice !
		$realext = str_replace("#", "", $extnum);
		if ( isset($dsarray[$realext]) )
		{
			debug("Extension '$realext' already in the dialstring, ignoring duplicate",1);
		}
		else
		{
			$dsarray[$realext] = 1;  // could be dial string i suppose but currently only using for duplicate check
			$extds = get_dial_string( $AGI, $extnum);
		    	$ds .= $extds . '&';
		
			// Update Caller ID for calltrace application
			if ((strpos($k,"#")==0) && (($rgmethod != "hunt") && ($rgmethod != "memoryhunt")) )
			{
				if (!isset($cidnum))
				{
					$rc = $AGI->database_put('CALLTRACE', $k, $cidnum);
					if ($rc == 1) 
					{
						debug("DbSet CALLTRACE/$k to $cidnum", 3);
					} 
					else 
					{
						debug("Failed to DbSet CALLTRACE/$k to $cidnum ($rc)", 1);
					}
				} 
				else 
				{
					// We don't care about retval, this key may not exist
					$AGI->database_del('CALLTRACE', $k);
					debug("DbDel CALLTRACE/$k - Caller ID is not defined", 3);
				}
			}
			else
			{
				$ext_hunt[$k]=$extds; // Need to have the extension HASH set with technology for hunt group ring 
			}
		}
	}
} // endforeach

$dshunt ='';
$loops=0;
$myhuntmember="";
if (($rgmethod == "hunt") || ($rgmethod == "memoryhunt")) 
{
	if ($cidnum) 
		$AGI->set_variable(CALLTRACE_HUNT,$cidnum);
		
	foreach ($extarray as $k )
	{ 
		// we loop through the original array to get the extensions in order of importance
		if ($ext_hunt[$k]) 
		{
			//If the original array is included in the extension hash then set variables
			$myhuntmember="HuntMember"."$loops";
			if ($rgmethod == "hunt") 
			{
				$AGI->set_variable($myhuntmember,$ext_hunt[$k]);
			} 
			elseif ($rgmethod == "memoryhunt") 
			{
				if ($loops==0) 
				{
					$dshunt =$ext_hunt[$k];
				} 
				else 
				{
					$dshunt .='&'.$ext_hunt[$k];
				}
				$AGI->set_variable($myhuntmember,$dshunt);
			}
			$loops += 1;
		}
	}
}

// chop $ds if length($ds); - removes trailing "&"
$ds = chop($ds," &");

if (!strlen($ds)) 
{
	$AGI->exec('NoOp');
} else {
	if (($rgmethod == "hunt") || ($rgmethod == "memoryhunt"))
	{
		$ds = '|';
		if ($timer)
			$ds .= $timer;
		$ds .= '|' . $dialopts; // pound to transfer, provide ringing
		$AGI->set_variable('ds',$ds);
		$AGI->set_variable("HuntMembers",$loops);
		$AGI->set_priority(20); // dial command is at priority 20 where dialplan handles calling a ringgroup with strategy of "hunt" or "MemoryHunt"
	} 
	else
	{
		$ds .= '|';
		if ($timer)
			$ds .= $timer;
		$ds .= '|' . $dialopts; // pound to transfer, provide ringing
		$AGI->set_variable('ds',$ds);
		$AGI->set_priority(10); // dial command is at priority 10
	}
}

// EOF dialparties.agi
exit( 0 );


// helper functions

function get_var( $agi, $value)
{
	$r = $agi->get_variable( $value );
	
	if ($r['result'] == 1)
	{
		$result = $r['data'];
		return $result;
	}
	else
		return '';
}

function get_dial_string( $agi, $extnum )
{
	$dialstring = '';
	
// 	if ($extnum =~ s/#//)
 	if (strpos($extnum,'#') != 0)
	{                       
		// "#" used to identify external numbers in forwards and callgourps
		$extnum = str_replace("#", "", $extnum);
		$dialstring = 'Local/'.$extnum.'@from-internal';
	} 
	else 
	{
		$device_str = sprintf("%d/device", $extnum);
		$device = $agi->database_get('AMPUSER',$device_str);
		$device = $device['data'];
		
		// a user can be logged into multipe devices, append the dial string for each		
		$device_array = split( '&', $device );
		foreach ($device_array as $adevice) 
		{
			$dds = $agi->database_get('DEVICE',$adevice.'/dial');
			$dialstring .= $dds['data'];
			$dialstring .= '&';
		}
		$dialstring = chop($dialstring," &");
	}
	
	return $dialstring;
}

function debug($string, $level=3)
{
	global $AGI;
	$AGI->verbose($string, $level);
}

function mycallback( $rc )
{
	debug("User hung up. (rc=" . $rc . ")", 1);
	exit ($rc);
}

function is_ext_avail( $extnum )
{  
	global $config;
		
	$astman = new AGI_AsteriskManager( );	
	if (!$astman->connect("127.0.0.1", $config["AMPMGRUSER"] , $config["AMPMGRPASS"]))
	{
		return false;
	}
	
	$status = $astman->ExtensionState( $extnum, 'from-internal' );
	$astman->disconnect();
		
	$status = $status['Status'];
	debug("ExtensionState: $status", 4);
	return $status;
	
}

function parse_amportal_conf($filename) 
{
	$file = file($filename);
	$matches = array();
	$matchpattern = '/^\s*([a-zA-Z0-9]+)\s*=\s*(.*)\s*([;#].*)?/';
	foreach ($file as $line) 
	{
		if (preg_match($matchpattern, $line, $matches)) 
		{
			$conf[ $matches[1] ] = $matches[2];
		}
	}
	return $conf;
}

?>
