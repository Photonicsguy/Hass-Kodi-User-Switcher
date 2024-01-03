#!/usr/bin/php
<?PHP

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.inc.php';

define('VER','0.21');

$mqtt = new \PhpMqtt\Client\MqttClient(constant('MQTTHOST'),constant('MQTTPORT'), constant('MQTTCLIENTID'));
$connectionSettings = (new \PhpMqtt\Client\ConnectionSettings)
    ->setConnectTimeout(3)
    ->setUsername(constant('MQTTUSER'))
	->setPassword(constant('MQTTPASS'))
	->setKeepAliveInterval(10)
    ->setReconnectAutomatically(true)
    ->setDelayBetweenReconnectAttempts(3000)
    ->setLastWillTopic('php/'.constant('MQTTTOPIC').'/avail')
	->setLastWillMessage('offline')
	->setLastWillQualityOfService(0)
	->setRetainLastWill(true);

echo "Version: \e[94m".constant('VER')."\e[0m".PHP_EOL;
while (true){
    try {
        connectMqtt($mqtt,$connectionSettings);
      } catch (Exception $e) {
          #echo "Caught \e[91m".$e->getMessage()."\e[0m\n";
          $s=30;
          echo "Waiting {$s} seconds...\n";
          sleep($s);
          echo "\n";
        continue;
      }
    echo "MQTT St";
    $mqtt->loop(true,true);
    echo "art\n";

    $lastPublish = 0;
    $lastMsg=0;
	    $updateDelay=60*15; // 15m
        $mqtt->registerLoopEventHandler(function (MqttClient $mqtt, float $elapsedTime) use (&$lastPublish, &$lastMsg,&$updateDelay) {
#            echo "{$lastPublish} + {$updateDelay} > ".time()."\n";
            if($lastMsg+10<=time()) return;
            $lastMsg=time();
            echo "t=".($updateDelay-(time()-$lastPublish))."\n";
            if ($lastPublish + $updateDelay > time()) { return; }
    		$lastPublish = time();
            updateProfileMQTT($mqtt);
	    });

        $mqtt->subscribe('php/'.constant('MQTTTOPIC').'/profile/cmd', function ($topic, $msg) {
		global $mqtt;
		global $count;
        printf("Received message on topic [%s]: %s\n", $topic, $msg);

        $list=getuser();
        if($msg!=$list->list[$list->cur]) {
            $id=array_search($msg,$list->list);
            switch_user($id);
            echo "Switched to {$id}\n";
        }else {
            echo "Same user: \$new='$msg', {$list->list[$list->cur]}\n";
        }
		updateProfileMQTT($mqtt,$msg);

        echo "Fin\n";
    	#$mqtt->interrupt();
        }, 0);
		updateProfileMQTT($mqtt);
#        $r=getuser();
#        $user=$r->list[$r->cur];
#        $mqtt->publish('php/'.constant('MQTTTOPIC').'/profile', $user, 0);
        while (true) {
		    echo "Loop start\n";
    		$mqtt->loop(true);
            echo "restarting loop\n";
        }

echo "Waiting 30s\n";
sleep(30);
} // End of while(true)

function updateProfileMQTT(&$mqtt,$nid=null) {
    static $id=-1;
    $r=getuser();
#    if($nid==null && $id==$r->cur) return 0;
    $user=$r->list[$r->cur];
    if($nid!=null) $user=$nid;
    $mqtt->publish('php/'.constant('MQTTTOPIC').'/profile', $user, 0);
    echo "Published \e[94m{$user}\e[0m to MQTT\n";
    $id=$r->cur;
    return(0);
}

function getuser() {
    static $list=null;
    if($list===null) {
        $json='{"jsonrpc": "2.0", "method": "Profiles.GetProfiles", "params": {}, "id":"GetProfiles"}';
        $res=rpc($json);
        foreach($res->result->profiles as $id=>$p) {
            if($p->label=='Master user') $p->label='Primary';
            $list[$id]=$p->label;
            #printf("ID: %2s  %-20s\n",$id,$p->label);
        }
    }
    $json='{"jsonrpc": "2.0", "method": "Profiles.GetCurrentProfile", "id":"CurProfile"}';
    $res=rpc($json);
    echo "Kodi active user is: \e[94m{$res->result->label}\e[0m\n";
    return (object) array('cur'=>array_search($res->result->label,$list),'list'=>$list);
}


function switch_user($id) {
    $json='{"jsonrpc": "2.0", "method": "GUI.ActivateWindow", "params": {"window":"home"}, "id":"Window"}';
    $res=rpc($json);
    if($res->result!='OK') {
        #echo "{$res->result}\n";
        print_r($res);
        die("Didn't receive OK when changing to Home window\n");
        return 1;
    }
    $list=getuser();
    $user=$list->list[$id];
    if($user=='Primary')$user='Master user';
    $json='{"jsonrpc": "2.0", "method": "Profiles.LoadProfile", "params": {"profile":"'.$user.'"}, "id":"CurProfile"}';
    $res=rpc($json);
    if($res->result!='OK') {
        #echo "{$res->result}\n";
        print_r($res);
        die("Didn't receive OK when switching to {$user}\n");
        return 1;
    }
    echo "Switched to '{$id}'\n";
    return 0;
}




function rpc($req) {    // Websocket option
    static $ws=null;
    static $cnt=0;
    global $hack;
    if($ws==null || !$ws->isConnected()) {
        // Required for websockets
        require_once("vendor/autoload.php");
        $ws = new WebSocket\Client(KODI_WS,['persistent'=>true]);
        $ws->text('{"jsonrpc": "2.0", "method": "Application.GetProperties", "params": { "properties": ["name","version"]}, "id":"GetAppProperties"}');
        do {
            $json=json_decode($ws->receive());
        } while (!property_exists($json,'id') || $json->id!='GetAppProperties');
        if($json->jsonrpc==2.0) $json=$json->result;
        if($hack)echo "Connected to \e[92m{$json->name}\e[0m, Version: \e[92m{$json->version->major}.{$json->version->minor}\e[0m\n";
        if(defined('KODI_EXPECTED_VER') && $json->version->major!=KODI_EXPECTED_VER) die("Echo expecting Kodi v".KODI_EXPECTED_VER.", discovered {$json->version->major}!\n");
    }
    if(is_string($req)) {   // If request is already in json, convert it back
        $req=json_decode($req);
    } elseif(is_array($req)) {  // if request is an array, convert it to an object
        $req=(object) $req;
    }
    $req->id.=$cnt++; 
    $ws->text(json_encode($req));       // Send query to Kodi
    do {
        $result=$ws->receive();         // Wait for response from Kodi
        if($result==null) die("Error with Kodi RPC call: ".print_r($req,true)."\n\n");
        $json=json_decode($result);
    #if(!property_exists($json,'id')) echo "Method: {$json->method}\n";
    } while (!property_exists($json,'id') || $req->id!=$json->id);      // Other messages will be received, not just our response

    return $json;
}
function connectMqtt(&$mqtt,&$connectionSettings) {
    try {
        $users=getuser();
        $userjson=json_encode($users->list);
	    $mqtt->connect($connectionSettings);
        $cfgBase='{"device":{"identifiers":["'.constant('MQTTTOPIC').'"],"manufacturer":"Jeff","model":"Kodi","name":"kodi","sw_version":"'.constant('VER').'","via_device":"'.gethostname().'"}}';

    $cfgProfile=mkConfig($cfgBase,'{"availability_topic":"php/'.constant('MQTTTOPIC').'/avail","json_attributes_topic":"php/'.constant('MQTTTOPIC').'/profile/attr","name":"Profile","icon":"mdi:account","state_topic":"php/'.constant('MQTTTOPIC').'/profile","command_topic":"php/'.constant('MQTTTOPIC').'/profile/cmd","options":'.$userjson.',"unique_id":"'.constant('MQTTTOPIC').'_profile"}');
    $mqtt->publish('homeassistant/select/'.constant('MQTTTOPIC').'/profile/config', $cfgProfile, 0);
    $mqtt->publish('php/'.constant('MQTTTOPIC').'/avail','online',0);
    $mqtt->loop(true,true); # Only call loop before subscribing

    }catch (Exception $e) {
	echo "Caught \e[91m".$e->getMessage()."\e[0m\n";
	try {
        $mqtt->disconnect();
    }catch (Exception $e) {}
    throw new Exception('connectMqtt Failed');
    }
}

function mkConfig($base,$cfg) {
    return json_encode(array_merge(json_decode($base,true),json_decode($cfg,true)));
}

