<?php

//******************************************************************************************
//* executes the ev-gen tool to calculate the eigenvalues/eigenvectors
//******************************************************************************************
function execEvGen($page_id, $wiki, $sid, $sd, $ed, $dmax) {
    if (PHP_OS == 'Linux' || PHP_OS == 'SunOS') {
        $result = shell_exec("evgen-bin/evgen $page_id $wiki $sd $ed $dmax $sid debug");
    }
    else {
        $result = shell_exec("evgen-bin\\evgen.exe $page_id $wiki $sd $ed $dmax $sid");
    }
    return $result != "0";
}

//******************************************************************************************
//* generates the data
//******************************************************************************************
function getData($wiki) {
    require("config.php");
    
    $page_id = $_SESSION['page_id'];
    
    $sid = session_id();

    //--- comment out for local testing ---
    //$dbserver = str_replace('_', '-', $wiki) . ".userdb.toolserver.org";
    //-------------------------------------
    
    $dbconn = mysql_connect($dbserver, $dbuser, $dbpassword);
    mysql_select_db($dbname, $dbconn);
    mysql_query("set names 'utf8';", $dbconn);
    
    $sd = getDateBy('sd');
    $ed = getDateBy('ed');
    $dmax = $_POST['dmax'];
    
    if ($ed != null) {
        //format: 20060331220000
        $sdf = $sd->format('YmdHis');
        $edf = $ed->format('YmdHis');
    }
    else {
        $sdf = $edf = 0;
        
        //check if recent revision edge data is available - if not, calculate revision edges
        $sql = "SELECT article FROM edge WHERE article = $page_id AND wiki = '$wiki' AND dmax = $dmax";
        if ($rs = mysql_query($sql, $dbconn)) {
            if (!($crow = mysql_fetch_row($rs))) {
                mysql_query("call getEdges('$wiki', $page_id, $dmax)", $dbconn);
            }
        }
        else {      //error
            echo "{ \"error\" : \"page not found\" }";
            return false;
        }
    }
    
    //calculate eigenvectors
    $result = execEvGen($page_id, $wiki, $sid, $sdf, $edf, $dmax);

    //this part checks if the evgen tool has finished inserting the data
    /*if ($result) {
        while (true) {
            $sql = "SELECT finished FROM evgen WHERE sid = '$sid'";
            $rs = mysql_query($sql, $dbconn);
            $crow = mysql_fetch_row($rs);

            if ($crow[0] == 0) {
                usleep(250000);
            }
            else {
                break;
            }
        }
    }
    else {  //error
        echo "{ \"error\" : \"eigenvector calculation failed\" }";
        return false;
    }*/
    
    $positions = array();
    $s = 0;
    $rsdmin = PHP_INT_MAX;
    $rsdmax = 0;
    
    $whereclause_edge = $whereclause = "article = $page_id AND wiki = '$wiki' AND dmax = $dmax";
    
    if ($ed != null) {
        $whereclause_edge .= " AND timestamp > $sdf AND timestamp < $edf";
    }

    //check if edges are available
    $sql = "SELECT count(*) FROM edge WHERE $whereclause_edge";
    $rs = mysql_query($sql, $dbconn);
    $crow = mysql_fetch_row($rs);
    if ($crow[0] > 0) {
        //get eigenvalue and calculate skewness
        $sql = "SELECT lambda1, lambda2 FROM eigenvalue WHERE $whereclause AND sid = '$sid'";
        $rs = mysql_query($sql, $dbconn);
        $crow = mysql_fetch_row($rs);
        $s = ($crow[0] == 0) ? 0 : $crow[1]/$crow[0];

        //get author's positions and extra data
        $sql = "SELECT user, v1, v2 FROM eigenvector WHERE $whereclause AND sid = '$sid'";
        $rs = mysql_query($sql, $dbconn);
        while ($crow = mysql_fetch_row($rs)) {
            $user = array();
            $user['name'] = $crow[0];
            $user['p1'] = (float) $crow[1];
            $user['p2'] = $s * $crow[2];
        
            //get out-degree and in-degree of author's revisions and edge data
            $user['out'] = 0;
            $user['in'] = 0;
            $user['revised'] = array();
        
            $sql = "SELECT SUM(weight), touser FROM edge WHERE fromuser = '$crow[0]' AND $whereclause GROUP BY touser";
            $rs_1 = mysql_query($sql, $dbconn);
            while ($crow_1 = mysql_fetch_row($rs_1)) {
                $user['out'] += $crow_1[0];
                $user['revised'][$crow_1[1]] = (float) $crow_1[0];
            }

            $sql = "SELECT SUM(weight) FROM edge WHERE touser = '$crow[0]' AND $whereclause";
            $rs_1 = mysql_query($sql, $dbconn);
            while ($crow_1 = mysql_fetch_row($rs_1)) {
                $user['in'] += $crow_1[0];
            }
            
            //get relative standard deviation
            $user['rsd'] = 0;
            $sql = "SELECT rsd FROM weeklyedits WHERE user = '$crow[0]' AND $whereclause";
            $rs_1 = mysql_query($sql, $dbconn);
            while ($crow_1 = mysql_fetch_row($rs_1)) {
                $user['rsd'] = $crow_1[0];
            }
            $rsdmin = $user['rsd'] < $rsdmin ? $user['rsd'] : $rsdmin;
            $rsdmax = $user['rsd'] > $rsdmax ? $user['rsd'] : $rsdmax;

            if ($user['p1'] != 0 || $user['p2'] != 0) {
                $positions[] = $user;
            }
        }
    }
    /* { "positions" : [
            { "name": "61.224.89.221",
              "p1": 1,
              "p2": 0,
              "out": 0.924792,
              "in": 0,
              "revised": {
                "67.71.3.90": 0.924792
              }
            },
            ...
         ],
         "skewness" : 0.24614068942402
       }
         */

    echo "{ \"positions\" : ";
    echo json_encode($positions);
    echo ", \"skewness\" : \"$s\"";
    echo ", \"rsdmin\" : \"$rsdmin\"";
    echo ", \"rsdmax\" : \"$rsdmax\"}";
}

//******************************************************************************************
//* gets the first element of an array
//******************************************************************************************
function getFirstElement($array) {
    return key(array_slice($array, 0, 1, true));
}

//******************************************************************************************
//* gets the last element of an array
//******************************************************************************************
function getLastElement($array) {
    end($array);
    return key($array);
}

//******************************************************************************************
//* gets the timeline data
//******************************************************************************************
function getTimelineData($wiki) {
    require("config.php");
    
    $page_id = $_SESSION['page_id'];
    
    //--- comment out for local testing ---
    //$dbserver = str_replace('_', '-', $wiki) . ".rrdb.toolserver.org";
    //-------------------------------------
    
    $dbconn = mysql_connect($dbserver, $dbuser, $dbpassword);
    
    //--- comment out for local testing ---
    //mysql_select_db($dbname, $dbconn);
    //mysql_query("set names 'utf8';", $dbconn);
    //-------------------------------------
    
    $sql = "SELECT year(rev_timestamp) as y, month(rev_timestamp) as m, count(*) as amount FROM $wiki.revision WHERE rev_page = $page_id GROUP BY year(rev_timestamp), month(rev_timestamp) ORDER BY rev_timestamp";
    $rs = mysql_query($sql, $dbconn);
    $dates = array();

    $first = true;
    $sYear = 0;
    $sMonth = 0;
    $eYear = 0;
    $eMonth = 0;
    while ($crow = mysql_fetch_row($rs)) {
        $m = str_pad($crow[1], 2, '0', STR_PAD_LEFT);
        $y = $crow[0];
        if ($first) {
            $sYear = $y;
            $sMonth = $m;
            $first = false;
        }

        $key = $y . "_" . $m;
        $dates[$key] = $crow[2];

        $eYear = $y;
        $eMonth = $m;
    }
    
    $startDate = new DateTime("20-$sMonth-$sYear");
    $endDate = new DateTime("20-$eMonth-$eYear");
    
    $current = $startDate;
    $item = "";
    $max = 0;
    $sd = getDateBy('sd');
    $ed = getDateBy('ed');
    
    while ($current <= $endDate) {
        $m = $current->format("m");
        $y = $current->format("Y");
        $key = $y . "_" . $m;
        $v = 0;
        if (array_key_exists($key, $dates)) {
            $v = $dates[$key];
        }
        
        if ($v > $max) {
            $max = $v;
        }
        
        $sel = "true";
        if ($sd != null && $ed != null) {
            if ($current < $sd || $current > $ed)
                $sel = "false";
        }

        $item .= "{ \"m\" : \"$m\", \"y\" : $y, \"a\" : $v, \"s\" : $sel }";
        if ($current < $endDate)
            $item .= ",";
        
        $current->modify("+1 month");
    }
    $start = $startDate->format("mY");
    $end = $endDate->format("mY");
    echo "{ \"start\" : \"$start\", \"end\" : \"$end\", \"max\" : $max, \"items\" : [$item]}";

    /*
    this._data = {
        start       : 012010,
        end         : 062010,
        max         : 7,
        items       : [{
            month   : 1,
            year    : 2010,
            amount  : 2,
            selected: false
        }
    */
}

//******************************************************************************************
// returns a date for a given name (post) or null
//******************************************************************************************
function getDateBy($name) {
    if ($_POST[$name] == null) {
        return null;
    } else {
        return new DateTime(date('d-m-Y', strtotime($_POST[$name])));
    }
}

//******************************************************************************************
// returns the page id for a given article
//******************************************************************************************
function get_page_id($wiki, $article) {
    require("config.php");
    
    //read revision data from the wikipedia database
    
    //--- comment out for local testing ---
    //$dbserver = str_replace('_', '-', $wiki) . ".rrdb.toolserver.org";
    
    $dbconn = mysql_connect($dbserver, $dbuser, $dbpassword);
    mysql_query("set names 'utf8';", $dbconn);
    
    mysql_select_db($wiki, $dbconn);
    
    $article = mysql_real_escape_string($article);
    $article = str_replace(' ', '_', $article);

    $data = array();
    
    $sql = "SELECT page_id from page WHERE page_namespace = 0 AND page_title = '$article'";
    $rs = mysql_query($sql, $dbconn);
    $crow = mysql_fetch_row($rs);

    $_SESSION['page_id'] = $crow[0];
    
    //--- comment out for local testing ---
    //mysql_close($dbconn);
}

//******************************************************************************************
// main function
//******************************************************************************************
function main() {
    date_default_timezone_set("UTC");
    
    session_start();
    
    if (isset($_POST['load'])) {
        $article = $_POST['article'];
        //--- use $dbnamelocalwiki for local testing ---
        //$wiki = $_POST['wiki'];
        $wiki = $dbnamelocalwiki;
        
        get_page_id($wiki, $article);
        
        getData($wiki);
    }
    else if (isset($_POST['timeline'])) {
        //--- use $dbnamelocalwiki for local testing ---
        //$wiki = $_POST['wiki'];
        $wiki = $dbnamelocalwiki;
        
        getTimelineData($wiki);
    }
}

main();

?>
