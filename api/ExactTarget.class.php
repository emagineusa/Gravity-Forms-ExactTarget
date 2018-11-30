<?php
/*
	ExactTarget for PHP

	_______________________________________

	Copyright (C) 2011 Katz Web Services, Inc.
	Authored by Zack Katz <zack@katzwebservices.com>

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.
*/
require('exacttarget_soap_client.php');

class ExactTarget {

	var $username = '';
	var $password = '';
	var $s4 = '';
    var $instance = "";
	var $debug = '';
	var $subscriberkey = '';
	var $mid = '';
    var $runscope = '';
    var $enforceRequired = '';


	function ExactTarget($apikey = null, $user = null, $password=null) {
		self::updateSettings($this);
	}

	public function updateSettings($object = false) {
		$api = new stdClass();
        $api->lastError = '';
		$settings = get_option("gf_exacttarget_settings");
		if(!$settings || !is_array($settings)) { return; }
		foreach($settings as $key => $value) {
			if($key === 'debug' || $key === 'subscriberkey') {
				$object->{$key} = !empty($value);
			} else {
				$object->{$key} = trim($value);
			}
		}
	}

	public function TestAPI() {
        try{
            $client = $this->getClient();
            $rr = new ExactTarget_RetrieveRequest();
            $rr->ObjectType = "BusinessUnit";
            $rr->Properties = array();
            $rr->Properties[] = "ID";
            $rr->Properties[] = "Name";
            $rr->Properties[] = "CustomerKey";
            $rr->Options =  null;
            if(empty($this->mid) || !is_numeric($this->mid)) {
                $rr->QueryAllAccounts = true;
            } else {
                $cl = new ExactTarget_ClientID();
                $cl->ID = $this->mid;
                $rr->ClientIDs = $cl;
            }
            $rrm = new ExactTarget_RetrieveRequestMsg();
            $rrm->RetrieveRequest = $rr;
            $results = $client->Retrieve($rrm);
            if(!(strtoupper($results->OverallStatus) == "OK")) {
                $this->lastError = $result->OverallStatus();
                return false;
            }
            return true;
        }
        catch(Exception $e) {
            $this->r('TestAPI error:: '.$e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
	}

	public function AddList($name = '', $type = 'Public') {
        try {
            $client = $this->getClient();
            $list = new ExactTarget_List();
            $list->Description = $name;
            $list->ListName = $name;
            $list->Type = $type;
            $object = new SoapVar($list,SOAP_ENC_OBJECT,'List',"http://exacttarget.com/wsdl/partnerAPI");
            $req = new ExactTarget_CreateRequest();
            $req->Options = null;
            $req->Objects = array($object);
            $results = $client->Create($req);
            if(!strtoupper($results->OverallStatus) == "OK") {
                $this->lastError = $result->OverallStatus();
                $this->r($this->lastError);
                return false;
            }
            return true;
        }
        catch(Exception $e){
            $this->r('AddList error:: '.$e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
	}

    private function setWSDL()
    {
        switch ( $this->instance ) {
            case 's4':
            case 's6':
            case 's7':
                $baseUrl = "webservice.{$this->instance}.exacttarget.com";
                break;

            default:
                $baseUrl = 'webservice.exacttarget.com';
                break;
        }
        //return 'https://webservice.s4.exacttarget.com/etframework.wsdl';
        if($this->runscope) {
            $baseUrl = str_replace("-","--",$baseUrl);
            $baseUrl = str_replace(".","-",$baseUrl);
            $baseUrl = $baseUrl . "-" . $this->runscope . ".runscope.net";
        }
        $baseUrl = "https://" . $baseUrl . "/etframework.wsdl";
        return $baseUrl;
    }

    private function getClient() {
        try {
            //todo: cache client
            $wsdl = $this->setWSDL();
            $client = new ExactTargetSoapClient($wsdl, array('trace'=>1));
            $client->username = $this->username;
            $client->password = $this->password;
            return $client;

        }
        catch(Exception $e){
            return null;
        }
    }

	public function Lists($showRaw = false) {
        try{
            flush();

            if((!isset($_REQUEST['refresh']) || isset($_REQUEST['refresh']) && $_REQUEST['refresh'] !== 'lists') && !isset($_REQUEST['retrieveListNames']) || false) {

                // Is it saved already in a transient?
                $lists = get_transient('extr_lists_all');
                if(!empty($lists) && is_array($lists)) {
                    return $lists;
                }

                // Check if raw data already exists
                $lists = get_transient('extr_lists_raw');
                if(!empty($lists) && is_array($lists)) {
                    return $lists;
                }

            } else {
                $lists = array();
            }

            $client = $this->getClient();

            $rr = new ExactTarget_RetrieveRequest();
            $rr->ObjectType = "List";
            $rr->Properties = array();
            $rr->Properties[] = "ID";
            $rr->Properties[] = "ListName";
            $rr->Properties[] = "Description";
            $rr->Properties[] = "Type";
            $rr->Options =  null;
            if(empty($this->mid) || !is_numeric($this->mid)) {
                $rr->QueryAllAccounts = true;
            } else {
                $cl = new ExactTarget_ClientID();
                $cl->ID = $this->mid;
                $rr->ClientIDs = $cl;
            }
            $rrm = new ExactTarget_RetrieveRequestMsg();
            $rrm->RetrieveRequest = $rr;
            $results = $client->Retrieve($rrm);
            if(!(strtoupper($results->OverallStatus) == "OK")) {
                $this->r('List retrieval failed.');
                $this->lastError = "List retrieval error, status: " . $results->OverallStatus;
                return false;
            }
            if(count($results->Results) > 20 || $showRaw)
            {
                foreach($results->Results as $list) {
                    $lists[$list->ID] = array('list_id' => (string)$list->ID, 'list_name' => $list->ListName, 'list_type' => $list->Type);
                }
                @set_transient('extr_lists_raw', $lists, 5); //60*60*24*365);
            } elseif(!$showRaw)
            {
                foreach($results->Results as $list) {
                    $lists[$list->ID] = array('list_id' => (string)$list->ID, 'list_name' => $list->ListName, 'list_type' => $list->Type);
                }
                @set_transient('extr_lists_all', $lists, 5); //60*60*24*365);
            }

            return $lists;
        }
        catch(Exception $e){
            $this->r('Lists error:: '.$e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
	}

	public function Attributes() {
        try{
            $attrs = array();

            $client = $this->getClient();
            $req = new ExactTarget_DefinitionRequestMsg();
            $odr = new ExactTarget_ObjectDefinitionRequest();
            $odr->ObjectType = 'Subscriber';
            $req->DescribeRequests = array($odr);
            if(empty($this->mid) || !is_numeric($this->mid)) {
                $req->QueryAllAccounts = true;
            } else {
                $cl = new ExactTarget_ClientID();
                $cl->ID = $this->mid;
                $req->ClientIDs = $cl;
            }
            $results = $client->Describe($req);
            if(!isset($results->ObjectDefinition->Properties) && !isset($results->ObjectDefinition->ExtendedProperties->ExtendedProperty)) {
                $this->r('Attribute retrieval failed.');
                $this->lastError = 'Attribute retrieval failed.';
                return false;
            }
            if((count($results->ObjectDefinition->Properties) < 1) && (count($results->ObjectDefinition->ExtendedProperties->ExtendedProperty) < 1) ) {
                $this->lastError = 'No properties for subscriber';
                return false;
            }
            foreach($results->ObjectDefinition->Properties as $prop) {
                if($prop->IsRetrievable && $this->CanAddAttribute($prop->Name)) {
                    $attrs[sanitize_user(str_replace(' ', '_', strtolower((string)$prop->Name)), true)] = (array)$prop;
                }
            }
            foreach($results->ObjectDefinition->ExtendedProperties->ExtendedProperty as $eprop) {
                if($eprop->IsViewable) {
                    $attrs[sanitize_user(str_replace(' ', '_', strtolower((string)$eprop->Name)), true)] = (array)$eprop;
                }
            }
            return $attrs;
        }
        catch(Exception $e){
            $this->r('Attributes error:: '.$e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
	}

    private static function CanAddAttribute($attName)
    {
        switch (strtolower($attName))
        {
            case 'id':
            case 'partnerkey':
            case 'createddate':
            case 'client.id':
            case 'client.partnerclientkey':
            case 'unsubscribeddate':
            case 'status':
            case 'isplatformobject':
                return false;
        }
        return true;
    }

	public function errorCodeMessage($errorcode = '', $errorcontrol = '') {

		switch($errorcode) {
  		        case "1" : $strError =	__("An error has occurred while attempting to save your subscriber information.", "gravity-forms-exacttarget"); break;
                case "2" : $strError =	__("The list provided does not exist.", "gravity-forms-exacttarget"); break;
                case "3" : $strError =	__("Information was not provided for a mandatory field. (".$errorcontrol.")", "gravity-forms-exacttarget"); break;
                case "4" : $strError =	__("Invalid information was provided. (".$errorcontrol.")", "gravity-forms-exacttarget"); break;
                case "5" : $strError =	__("Information provided is not unique. (".$errorcontrol.")", "gravity-forms-exacttarget"); break;
                case "6" : $strError =	__("An error has occurred while attempting to save your subscriber information.", "gravity-forms-exacttarget"); break;
                case "7" : $strError =	__("An error has occurred while attempting to save your subscriber information.", "gravity-forms-exacttarget"); break;
                case "8" : $strError =	__("Subscriber already exists.", "gravity-forms-exacttarget"); break;
                case "9" : $strError =  __("An error has occurred while attempting to save your subscriber information.", "gravity-forms-exacttarget"); break;
                case "10" : $strError = __("An error has occurred while attempting to save your subscriber information.", "gravity-forms-exacttarget"); break;
                case "12" : $strError =	__("The subscriber you are attempting to insert is on the master unsubscribe list or the global unsubscribe list.", "gravity-forms-exacttarget"); break;
                case "13" : $strError =	__("Check that the list ID and/or MID specified in your code is correct.", "gravity-forms-exacttarget"); break;
                default : $strError =	__("Error", "gravity-forms-exacttarget"); break;
        }
        return $strError;
	}

	public function listSubscribe($lists = array(), $email = '', $merge_vars = array()) {
        try{
            $this->lastError = '';

            if(!is_array($lists)) { $lists = explode(',',$lists); }

            if(empty($this->mid)) {
                $this->lastError = 'The MID was not defined in the ExactTarget settings. The attempt to add the subscriber was not made.';
                $this->r($this->lastError);
                return;
            } elseif(empty($lists)) {
                $this->lastError = 'No lists were selected. The attempt to add the subscriber was not made.';
                $this->r($this->lastError);
                return;
            }

            $params = $merge_vars;

            foreach($params as $key => $p) {
                if(is_array($p)) {
                    $p = implode(', ', $p);
                } else {
                    $p = rtrim(trim($p));
                }
                if(empty($p) && $p !== '0') {
                    unset($params[$key]);
                } else {
                    $params[$key] = $p;
                }
            }

            foreach($params as $key => $value) {
                $newkey = ucwords(str_replace('_', ' ', $key));
                $params["{$newkey}"] = esc_html($value);
                unset($params[$key]);
            }

            $client = $this->getClient();
            $sub = new ExactTarget_Subscriber();
            $sub->EmailAddress = $email;
            if($this->subscriberkey) {
                $sub->SubscriberKey = $email;
            }
            if(empty($this->mid) || !is_numeric($this->mid)) {
                $sub->QueryAllAccounts = true;
            } else {
                $cl = new ExactTarget_ClientID();
                $cl->ID = $this->mid;
                $sub->Client = $cl;
            }

            $sub->Lists = array();
            foreach($lists as $key => $value)
            {
                $sl = new ExactTarget_SubscriberList();
                $sl->ID = $value;
                $sl->Status = ExactTarget_SubscriberStatus::Active;
                $sub->Lists[] = $sl;
            }


            $sub->Attributes = array();
             foreach($params as $key => $value) {
                if(empty($key)) { continue; }
                if(strtolower($key) == 'emailaddress') { continue;}
                if(strtolower($key) == 'subscriberkey') { continue;}
                $attrib = new ExactTarget_Attribute();
                $attrib->Name = $key;
                $attrib->Value = $value;
                $sub->Attributes[] = $attrib;
            }


            $so = new ExactTarget_SaveOption();
            $so->PropertyName = "*";
            $so->SaveAction = ExactTarget_SaveAction::UpdateAdd;
            $soe = new SoapVar($so, SOAP_ENC_OBJECT, 'SaveOption',"http://exacttarget.com/wsdl/partnerAPI");
            $opt = new ExactTarget_UpdateOptions();
            $opt->SaveOptions = array($soe);
            $sub->Status = ExactTarget_SubscriberStatus::Active;
            $obj = new SoapVar($sub, SOAP_ENC_OBJECT, 'Subscriber', "http://exacttarget.com/wsdl/partnerAPI");
            $req = new ExactTarget_CreateRequest();
            $req->Options = $opt;
            $req->Objects = array($obj);
            $results = $client->Create($req);
            if(strtoupper($results->OverallStatus) != "OK") {
                $this->r('Add/update subscriber failed: '.$results->StatusMessage);
                $this->lastError = $results->StatusMessage;
                return false;

            }

            return true;
        }
        catch(Exception $e) {
            $this->r('ListSubscribe error:: '.$e->getMessage());
            $this->lastError = $e->getMessage();
            return false;
        }
	}

	function r($debugging = '', $title = '') {
		if($this->debug && current_user_can('manage_options') && !is_admin()) {
			echo '<div style="background-color:white;border:1px solid #ccc; padding:10px; margin:6px; position:relative; font-size:14px;">
				<p style="text-align:center; color:#ccc; margin:0; padding:0; position:absolute; right:.5em; top:.5em;">Admin-only Debugging Results</p>
			';
				if($title) {
					echo '<h3>'.$title.'</h3>';
				}
				echo '<pre>';
					print_r($debugging);
				echo '</pre>
			</div>';
		}
	}
}