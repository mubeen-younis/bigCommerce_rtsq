<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use mysqli;

class DbExportController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public static function exportDbData()
    {
        $chunkSize = 500;
        $request = [];
        $url = '';

        $db_name = 'bg_cmrc';
        $db_user = 'root';
        $db_pass = '';
        $db_host = '127.0.0.1';
        $connect_db = '';

        // for backup to dev3 server
        $db_name2 = 'test_bc';
        $db_user2 = 'root';
        $db_pass2 = '';
        $db_host2 = '127.0.0.1';
        $connect_db2 = 'localhost';

        $connect_db = new mysqli($db_host, $db_user, $db_pass, $db_name);
        $connect_db2 = new mysqli($db_host2, $db_user2, $db_pass2, $db_name2);
        if (mysqli_connect_errno()) {
            printf("Connection failed: %s\
            ", mysqli_connect_error());
            exit();
        }

        // request_temp table data export
        $requestTempResults = self::runQueries('request_temp', '2 DAY', $connect_db, $connect_db2);

        // app_logs table data export
        $appLogsResults = self::runQueries('app_logs', '4 HOUR', $connect_db, $connect_db2);
    }

    private static function runQueries($tableName, $interval, $dbConnection1, $dbConnection2)
    {
        // get select query
        $qry = self::getSelectDataQuery($tableName, $interval);
        // fetch data from DB
        $result = self::runQuery($qry, $dbConnection1);
        // insert records in other DB
        $insertedRows = self::insertDataIntoDB($tableName, $result, $dbConnection2);
        // get delete query
        $qry = self::getDeleteDataQuery($tableName, $interval);
        // delete data from previous DB
        $result = self::runQuery($qry, $dbConnection1);

        return $result;
    }

    private static function getSelectDataQuery($tableName, $interval)
    {
        $qry = "SELECT * FROM {$tableName} WHERE created_at <= DATE_SUB(NOW(), INTERVAL {$interval})";

        return $qry;
    }

    private static function insertDataIntoDB($tableName, $result = [], $dbConnection)
    {
        foreach ($result as $log) {
            if ($tableName == 'app_logs') {
                $insert_query = "INSERT INTO {$tableName} VALUES ('$log->id', '$log->message', '$log->context', '$log->level', '$log->level_name', '$log->channel', '$log->record_datetime', '$log->extra', '$log->formatted', '$log->remote_addr', '$log->user_agent', '$log->created_at')";
            } else if ($tableName == 'request_temp') {
                $insert_query = "INSERT INTO {$tableName} VALUES ('$log->id', '$log->rate_id', '$log->cart_id', '$log->request', '$log->lineitems', '$log->quotes', '$log->response', '$log->multiShipmentresponse', '$log->shipping_group_resp', '$log->dbsc_resp', '$log->box_bins', '$log->updated_at', '$log->created_at')";
            }

            $result = mysqli_query($dbConnection, $insert_query);
        }

        return $result;
    }

    private static function getDeleteDataQuery($tableName, $interval)
    {
        $qry = "DELETE FROM {$tableName} WHERE created_at <= DATE_SUB(NOW(), INTERVAL {$interval})";

        return $qry;
    }

    public function sendCurlRequest($url, $postData)
    {
        $fieldString = http_build_query($postData);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldString);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($ch);
            curl_close($ch);

            return json_decode($output, true);
        } catch (\Throwable$e) {
            $result = [];
        }

        return $result;
    }

    /**
     * execute query string
     * @param string $qry
     * @return array
     */
    public static function runQuery($qry, $connect_db)
    {
        @$res = array();
        $q = "$qry";
        @$result = mysqli_query($connect_db, $q);
        @$number = mysqli_num_rows($result);

        if (@$number > 0) {
            // print them one after another
            while ($row = mysqli_fetch_object($result)) {
                $res[] = $row;
            }
        }

        return $res;
    }
}
