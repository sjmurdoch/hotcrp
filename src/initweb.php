<?php
// initweb.inc -- HotCRP initialization for web scripts
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("init.php");

// Set up conference path variables
function set_path_variables() {
    global $ConfSiteBase, $ConfSiteSuffix;

    // Determine relative path to conference home in URLs
    if (isset($_SERVER["PATH_INFO"]))
	$ConfSiteBase = str_repeat("../", substr_count($_SERVER["PATH_INFO"], "/"));
    else {
	$toplev = array("Code" => 1, "doc" => 1, "doc.php" => 1);
	$url = $_SERVER["PHP_SELF"];
	$ndotdot = 0;
	$ConfSiteBase = "";
	while (($rpos = strrpos($url, "/")) !== false) {
	    $last = substr($url, $rpos + 1);
	    if (isset($toplev[$last])) {
		$ConfSiteBase = str_repeat("../", $ndotdot);
		break;
	    }
	    $ndotdot++;
	    $url = substr($url, 0, $rpos);
	}
    }

    // Determine whether to include the .php suffix
    $ConfSiteSuffix = ".php";
    if (function_exists("apache_get_modules")
        && array_search("mod_rewrite", apache_get_modules()) !== false)
        $ConfSiteSuffix = "";
}

set_path_variables();


// Check for obsolete pages.
// These are pages that we've removed from the source. But some user might
// have an old version of the page lying around their directory. Don't run
// that code; redirect to index.
if (array_search(request_script_base(),
                 array("login", "logout", "contactauthors")) !== false)
    go();


// Redirect if options unavailable
global $Opt;
if (!@$Opt["loaded"]) {
    if (isset($_REQUEST["ajax"]) && $_REQUEST["ajax"]) {
        if (isset($_REQUEST["jsontext"]) && $_REQUEST["jsontext"])
            header("Content-Type: text/plain");
        else
            header("Content-Type: application/json");
        echo "{\"error\":\"unconfigured installation\"}\n";
    } else {
        echo "<html><head><title>HotCRP error</title><head><body><h1>Unconfigured HotCRP installation</h1>";
        echo "<p>HotCRP has been installed, but you haven’t yet configured a conference. You must run <code>Code/createdb.sh</code> to create a database for your conference. See <code>README.md</code> for further guidance.</p></body></html>\n";
    }
    exit;
}


// Multi-conference support
function setMulticonference() {
    global $ConfSiteBase, $ConfMulticonf, $Opt;

    $url = explode("/", $_SERVER["PHP_SELF"]);
    $npop = strlen($ConfSiteBase) / 3;
    if ($url[count($url) - 1] == "")
	$npop++;
    if ($npop + 2 > count($url))
	return;
    $ConfMulticonf = $url[count($url) - $npop - 2];

    $nchanged = 0;
    foreach (array("dbName", "dbUser", "dbPassword", "dsn") as $k)
	if (isset($Opt[$k])) {
	    $Opt[$k] = str_replace("*", $ConfMulticonf, $Opt[$k]);
	    ++$nchanged;
	}
    if ($nchanged == 0)
	$Opt["dbName"] = $ConfMulticonf;

    foreach (array("sessionName", "downloadPrefix", "conferenceSite",
		   "paperSite") as $k)
	if (isset($Opt[$k]))
	    $Opt[$k] = str_replace("*", $ConfMulticonf, $Opt[$k]);

    if (!isset($Opt["downloadPrefix"]))
	$Opt["downloadPrefix"] = $ConfMulticonf . "-";
}

if (isset($Opt["multiconference"]) && $Opt["multiconference"])
    setMulticonference();


// Create the conference
global $Conf;
if (!@$Conf) {
    $Opt["dsn"] = Conference::make_dsn($Opt);
    $Conf = new Conference($Opt["dsn"]);
}
if (!$Conf->dblink)
    die("Unable to connect to database at " . Conference::sanitize_dsn($Opt["dsn"]) . "\n");


// How long before a session is automatically logged out?
//
// Note that on many installations, a cron job garbage-collects old
// sessions.  That cron job ignores local 'session.gc_maxlifetime' settings,
// so you'll also need to change the system-wide setting in 'php.ini'.
$Opt["globalSessionLifetime"] = ini_get('session.gc_maxlifetime');
if (!isset($Opt["sessionLifetime"]))
    $Opt["sessionLifetime"] = 86400;
ini_set('session.gc_maxlifetime', defval($Opt, "sessionLifetime", 86400));


// Check and fix Zlib output compression
global $zlib_output_compression;
$zlib_output_compression = false;
if (function_exists("zlib_get_coding_type"))
    $zlib_output_compression = zlib_get_coding_type();
if ($zlib_output_compression) {
    header("Content-Encoding: $zlib_output_compression");
    header("Vary: Accept-Encoding", false);
}

ensure_session();


// Initialize user
function initialize_user() {
    global $Opt, $Me;

    // backwards compat: set $_SESSION["user"] from $_SESSION["Me"]
    if (!isset($_SESSION["user"]) && isset($_SESSION["Me"])) {
        $x = $_SESSION["Me"];
        $_SESSION["user"] = "$x->contactId $x->confDsn $x->email";
        unset($_SESSION["Me"]);
    }

    // load current user
    $userwords = array();
    if (isset($_SESSION["user"]))
        $userwords = explode(" ", $_SESSION["user"]);
    $Me = null;
    if (count($userwords) >= 2 && $userwords[1] == $Opt["dsn"])
        $Me = Contact::find_by_id($userwords[0]);
    else if (count($userwords) >= 3)
        $Me = Contact::find_by_email($userwords[2]);
    if (!$Me) {
        $Me = new Contact;
        $Me->fresh = true;
    }
    $Me = $Me->activate();
}

global $Me;
initialize_user();


// Extract an error that we redirected through
if (isset($_SESSION["redirect_error"])) {
    global $Error;
    $Error = $_SESSION["redirect_error"];
    unset($_SESSION["redirect_error"]);
}

// Mark as already expired to discourage caching, but allow the browser
// to cache for history buttons
session_cache_limiter("");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Cache-Control: private");