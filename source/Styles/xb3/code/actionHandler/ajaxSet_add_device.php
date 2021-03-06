<?php 

/**
 *  Description: Judge whether the user input ip valid or not (based on current gw ip and subnetmask)
 *  parameter  : input IP address
 *  return     : bool(TRUE/FALSE)
 */
function isIPValid($IP){

    $ret        = TRUE;
    $LanSubMask = getStr("Device.X_CISCO_COM_DeviceControl.LanManagementEntry.1.LanSubnetMask");
    $LanGwIP    = getStr("Device.X_CISCO_COM_DeviceControl.LanManagementEntry.1.LanIPAddress");
    $gwIP       = explode('.', $LanGwIP);
    $hostIP     = explode('.', $IP); 

    if ($LanGwIP == $IP) {
        $ret = FALSE;
    }
    elseif (strstr($IP, '172.16.12')) {
        //$msg = "This ip is reserved for home security";
        $ret = FALSE;
    }     
    elseif (strstr($LanSubMask, '255.255.255')) {
        //the first three field should be equal to gw ip field
        if (($gwIP[0] != $hostIP[0]) || ($gwIP[1] != $hostIP[1]) || ($gwIP[2] != $hostIP[2])) {
           //$msg = "Input IP is not in valid range:\n" . "$gwIP[0].$gwIP[1].$gwIP[2].[2~254]";
           $ret = FALSE;
        }      
    }
    elseif ($LanSubMask == '255.255.0.0') {
        if (($gwIP[0] != $hostIP[0]) || ($gwIP[1] != $hostIP[1])) {
           //$msg = "Input IP is not in valid range:\n" . "$gwIP[0].$gwIP[1].[2~254].[2~254]";
           $ret = FALSE;
        }      
    } 
    else {
        if ($gwIP[0] != $hostIP[0]) {
           //$msg = "Input IP is not in valid range:\n [10.0.0.2 ~ 10.255.255.254]";
           $ret = FALSE;
        } 
    } 

    if ($ret) {
        //if above check pass, then check whether the IP have been used or not      
        $idArr = explode(",", getInstanceIds("Device.DHCPv4.Server.Pool.1.StaticAddress."));
        foreach ($idArr as $key => $value) {
            if ( !strcasecmp(getStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$value.Yiaddr"), $IP) ) {
                $ret = FALSE;
                break;
            }
        }
    }  

    return $ret;
}

$deviceInfo = json_decode($_REQUEST['DeviceInfo'], true);
$result     = "";

if( !array_key_exists('delFlag', $deviceInfo) ) {

    //key kelFlag is not exist, so this is to reserve a ip addr for host 
    //firstly check whether this device is already in the reserved ip list
    $exist   = false;
    $macAddr = $deviceInfo['macAddress'];
    $ipAddr  = $deviceInfo['reseverd_ipAddr'];

    if (array_key_exists('UpdateComments', $deviceInfo)){
        //from edit device page scenario: DHCP ==> DHCP
        //only update comments for this device connected via DHCP
        $idArr = explode(",", getInstanceIds("Device.Hosts.Host."));
        foreach ($idArr as $key => $value) {
            $macArr["$value"] =  getStr("Device.Hosts.Host.$value.PhysAddress");
        }
        foreach ($macArr as $key => $value) {
            if ( !strcasecmp($value, $macAddr) ) {
              $index = $key;  
              break;
            }
        }
        if( isSet($index) ){
           setStr("Device.Hosts.Host.$index.Comments", $deviceInfo['Comments'], true);
        }    

        $result = "success";        
    }//end of array_key_exist updateComments

    //First of all, check whether the user post IP address available or not
    elseif (isIPValid($ipAddr) == FALSE) {
        $result = "Invlid IP address, please input again.";
    }
    else{

        $idArr = explode(",", getInstanceIds("Device.DHCPv4.Server.Pool.1.StaticAddress."));
        foreach ($idArr as $key => $value) {
            if ( !strcasecmp(getStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$value.Chaddr"), $macAddr) ) {
                $exist = true;
                $existIndex = $value;
                break;
            }
        }

        if( ! $exist ){
            /*
            * there are two scenarios: 
            *  1. DHCP ==> ReservedIP, add entry, update host comments
            *  2. ReservedIP ==> ReservedIP, mac address changed, modify this static entry, update host comments meanwhile
            */
            addTblObj("Device.DHCPv4.Server.Pool.1.StaticAddress.");
            $IDs  = getInstanceIds("Device.DHCPv4.Server.Pool.1.StaticAddress.");

            $idArr = explode(",", $IDs);
            $instanceid = array_pop($idArr);

            setStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$instanceid.X_CISCO_COM_DeviceName", $deviceInfo['hostName'], false);
            setStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$instanceid.Chaddr", $deviceInfo['macAddress'], false);
            setStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$instanceid.Yiaddr", $deviceInfo['reseverd_ipAddr'], false);
            
            if(setStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$instanceid.X_CISCO_COM_Comment", $deviceInfo['Comments'], true)){
                $result = "success";
            }

            if (array_key_exists('addResvIP', $deviceInfo)){
                //this post is from add device page, only set staticAddress table, do nothing any more
            }
            else{
                //this post is from edit device page, set Host talbe comments as well.
                $idArr = explode(",", getInstanceIds("Device.Hosts.Host."));
                $macArr = array();
                foreach ($idArr as $key => $value) {
                    $macArr["$value"] =  getStr("Device.Hosts.Host.$value.PhysAddress");
                }
                foreach ($macArr as $key => $value) {
                    if ( !strcasecmp($value, $macAddr) ) {
                      $index = $key;  
                      break;
                    }
                }
                if( isSet($index) ){
                   setStr("Device.Hosts.Host.$index.Comments", $deviceInfo['Comments'], true);
                }
            }//end of else
        } //end of exist
        else{
            if ( array_key_exists('addResvIP', $deviceInfo) ) {
                $result = "Confilct MAC address, please input again.";
            }
            else {
                /* 
                * From edit device scenario: ReservedIP  ==> ReservedIP, only update static table entry, and host comments
                */
                setStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$existIndex.Chaddr", $deviceInfo['macAddress'], false);
                setStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$existIndex.Yiaddr", $deviceInfo['reseverd_ipAddr'], false);
                if(setStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$existIndex.X_CISCO_COM_Comment", $deviceInfo['Comments'], true)){
                    $result = "success";
                }

                $idArr = explode(",", getInstanceIds("Device.Hosts.Host."));
                $macArr = array();
                foreach ($idArr as $key => $value) {
                    $macArr["$value"] =  getStr("Device.Hosts.Host.$value.PhysAddress");
                }
                foreach ($macArr as $key => $value) {
                    if ( !strcasecmp($value, $macAddr) ) {
                      $index = $key;  
                      break;
                    }
                }
                if( isSet($index) ){
                   setStr("Device.Hosts.Host.$index.Comments", $deviceInfo['Comments'], true);
                }

            }// end of else
        }
    }//end of else isIPValid
}
else{
    //from edit page scenario: Reserved IP => DHCP
    //this is going to remove the corresponding reserved ip in static address table 
    $macAddr = $deviceInfo['macAddress'];
    $idArr = explode(",", getInstanceIds("Device.DHCPv4.Server.Pool.1.StaticAddress."));

    foreach ($idArr as $key => $value) {
        $macArr["$value"] =  getStr("Device.DHCPv4.Server.Pool.1.StaticAddress.$value.Chaddr");
    }

    foreach ($macArr as $key => $value) {
        if ( !strcasecmp($value, $macAddr) ) {
          $index = $key;  
          break;
        }
    }

    if( isSet($index) ){
       delTblObj("Device.DHCPv4.Server.Pool.1.StaticAddress.$index.");    
    }

    $idArr = explode(",", getInstanceIds("Device.Hosts.Host."));
    unset($macArr); // this is very important 
    foreach ($idArr as $key => $value) {
        $macArr["$value"] =  getStr("Device.Hosts.Host.$value.PhysAddress");
    }
    foreach ($macArr as $key => $value) {
        if ( !strcasecmp($value, $macAddr) ) {
          $i = $key;  
          break;
        }
    }
    if( isSet($i) ){
       setStr("Device.Hosts.Host.$i.Comments", $deviceInfo['Comments'], true);
       setStr("Device.Hosts.Host.$i.AddressSource", "DHCP", true);
    }

    $result = "success";
}

echo json_encode($result);

?>
