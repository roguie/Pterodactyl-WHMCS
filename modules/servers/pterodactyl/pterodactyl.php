<?php
/**
 * Pterodactyl WHMCS Module
 *
 * @copyright Copyright (c) Emmet Young 2016
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

function pterodactyl_api_call($publickey, $privatekey, $url, $type, array $data = NULL )
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $type);
	curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);

    if(isset($data))
    {
        $data = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }		
	
    $hmac = hash_hmac('sha256', $url . $data, $privatekey, true);
	
    $headers = array("Authorization: Bearer " . $publickey . '.' . base64_encode($hmac),
					 "Content-Type: application/json; charset=utf-8");
	
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		
    $responsedata = array('data' => json_decode(curl_exec($curl)),
                          'status_code' => curl_getinfo($curl, CURLINFO_HTTP_CODE));

    curl_close($curl);

    return $responsedata;
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related abilities and
 * settings.
 *
 * @see http://docs.whmcs.com/Provisioning_Module_Meta_Data_Parameters
 *
 * @return array
 */
function pterodactyl_MetaData()
{
    return array( 
        'DisplayName' => 'Pterodactyl',
        'APIVersion' => '1.1', // Use API Version 1.1
        'RequiresServer' => true, // Set true if module requires a server to work
    );
}

/**
 * Define product configuration options.
 *
 * @return array
 */
function pterodactyl_ConfigOptions()
{
	return array(
		'memory' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '1024',
			'Description' => 'Total memory (in MB) to assign to the server',
		),
		'swap' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '256',
			'Description' => 'Total swap (in MB) to assign to the server',
		),
		'cpu' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '50',
			'Description' => 'Cpu limit, value is as a percentage of each core. One core being 100%.',
		),
		'io' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '500',
			'Description' => 'Block IO adjustment number.',
		),
		'disk' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '1024',
			'Description' => 'Total disk space (in MB) to assign to the server.',
		),
		'location' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '1',
			'Description' => 'ID of location in which server should be created.',
		),
		'service' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '1',
			'Description' => 'ID of the service this server is using.',
		),
		'option' => array(
			'Type' => 'text',
			'Size' => '10',
			'Default' => '1',
			'Description' => 'ID of the specific service option this server is using.',
		),
		'startup' => array(
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'The startup parameters this server is using.',
		),
		'auto_deploy' => array(
			'Type' => 'yesno',
			'Default' => 'yes',
			'Description' => 'Tick to enable auto deploy. You do not need the below options with auto deploy enabled.',
		),
		'node' => array(
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'ID of the node to assign the server to (must be apart of the specified location id).',
		),
		'allocation' => array(
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'The allocation ID to use for the server (only if not using auto_deploy, and not using ip and port).',
		),
		'ip' => array(
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'IP address of existing allocation to assign to server.',
		),
		'port' => array(
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => 'Port of existing allocation to assign to server. (Must include above IP address).',
		),
	);
}

/**
 * Provision a new instance of a product/service.
 *
 * Attempt to provision a new instance of a given product/service. This is
 * called any time provisioning is requested inside of WHMCS. Depending upon the
 * configuration, this can be any of:
 * * When a new order is placed
 * * When an invoice for a new order is paid
 * * Upon manual request by an admin user
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function pterodactyl_CreateAccount(array $params)
{
    try {
		$newAccount = false;

		$url = $params['serverhostname'].'/api/users/'.$params['userid'];

		$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'GET');
		
		if ($response['status_code'] == 404)
		{
			//Begin by creating the user on the panel side
			$url = $params['serverhostname'].'/api/users/';
			
			$data = array("email" => $params['clientsdetails']['email'],
						  "admin" => false, 
						  "password" => $params['password'],
						  "custom_id" => $params['userid'],
						 );

			$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'POST', $data);
			
			if($response['status_code'] != 200)
			{
				return "Error during create account: ".$response['data']->message.json_encode($data);
			}
			
			$newAccount = true;
		}
		
		if($response['status_code'] == 200)
		{
			//Now get the panel to create a new server for our new user.
			$new_server = array("name" => $params['username']."_".$params['serviceid'],
						  "owner" => $params['clientsdetails']['email'], 
						  "memory" => $params['configoption1'],
						  "swap" => $params['configoption2'],
						  "cpu" => $params['configoption3'],
						  "io" => $params['configoption4'],
						  "disk" => $params['configoption5'],
						  "location" => $params['configoption6'],
						  "service" => $params['configoption7'],
						  "auto_deploy" => $params['configoption10'] === 'on' ? true : false,
						  "custom_id" => $params['serviceid'],
						 );
			
			$service = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $params['serverhostname'].'/api/services/'.$params['configoption7'], 'GET');		

			$new_server["startup"] = isset($params['configoption9']) ? $params['configoption9'] : $service['data']->service->startup;
			
			if(isset($params['configoptions']['option_tag']))
				$option_tag = $params['configoptions']['option_tag'];
			else if(isset($params['customfields']['option_tag']))
				$option_tag = $params['customfields']['option_tag'];		
			
			foreach($service['data']->options as $option)
			{
				if((isset($option_tag) && (strcasecmp($option->tag, $params['configoptions']['option_tag']) == 0)) || 
				   (!isset($option_tag) && ($params['configoption8'] == $option->id)))
				{
					$new_server['option'] = $option->id;
					foreach($option->variables as $variable)
					{
						
						if(isset($params['configoptions']["env_".$variable->env_variable]))
							$env_varaiable = $params['configoptions']["env_".$variable->env_variable];
						else if(isset($params['customfields']["env_".$variable->env_variable]))
							$env_varaiable = $params['customfields']["env_".$variable->env_variable];
						
						$new_server["env_".$variable->env_variable] = isset($env_varaiable) ? $env_varaiable : $variable->default_value;
					}
					break;
				}
			}
		
			if ($params['configoption10'] === 'off')
			{
				$new_server["node"] = $params['configoption11'];
				if(!isset($params['configoption12']))
				{
					$new_server["allocation"] = $params['configoption12']; 
				}
				else
				{
					$new_server["ip"] = $params['configoption13'];
					$new_server["port"] = $params['configoption14'];
				}
			}
						 
			$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $params['serverhostname'].'/api/servers', 'POST', $new_server);		
			
			if($response['status_code'] != 200)
			{
				return "Error during create server: ".$response['data']->message;
			}
		}
		else
		{
			return "Error during search for existing client: ".$response['data']->message;
		}
		
		//Grab the admin ID, makes it easier to make calls to 
		$adminid = Capsule::table('tbladmins')->where('disabled',0)->where('roleid',1)->pluck('id');  

		//Setup all the parameters we want to pass into the email
		$clientname = $params['clientsdetails']['fullname'];
		$panelurl = $params['serverhostname'];
		$clientemail = $params['clientsdetails']['email'];
		$clientpassword = $params['password'];
		
		$clientId['clientid'] = $params['userid'];
		$clientServiceId['serviceid'] = $params['serviceid'];
		
		//Call the WHMCS api to get client details, we need this to display the currency code
		$clientdetails = localAPI("getclientsdetails", $clientId, $adminid[0]);
		//Also call the WHMCS API to get the product details for the client
		$clientproducts = localAPI("getclientsproducts", $clientServiceId, $adminid[0]);
		
		$service_product_name = $clientproducts['products']['product'][0]['name'];
		$service_payment_method = $clientproducts['products']['product'][0]['paymentmethodname'];
		$service_billing_cycle = $clientproducts['products']['product'][0]['billingcycle'];
		$service_next_due_date  = $clientproducts['products']['product'][0]['nextduedate'] == "0000-00-00" ? "----" :  $clientproducts['products']['product'][0]['nextduedate'];
		$service_recurring_amount  = "$".$clientproducts['products']['product'][0]['recurringamount']." ".$clientdetails['currency_code']; 
		
		//Format the email for sending
		$email["customtype"] = "product";
		$email["customsubject"] = "New Product Information";
		$email["custommessage"] = "<p>Dear $clientname,</p>
								   <p>Your order for <b>$service_product_name</b> has now been activated. Please keep this message for your records.</p>
								   <p><b>Product/Service:</b> $service_product_name <br />
								   <b>Payment Method:</b> $service_payment_method <br />
								   <b>Amount:</b> $service_recurring_amount <br />
								   <b>Billing Cycle:</b> $service_billing_cycle <br />
								   <b>Next Due Date:</b> $service_next_due_date <br /> <br />
								   <b>Panel Login URL:</b> <a href='$panelurl'>$panelurl</a><br />
								   <b>Panel Login Email:</b> $clientemail <br />";
		if ($newAccount)
		{
			$email["custommessage"] .= "<b>Panel Login Password:</b> $clientpassword <br />";
		}
		else
		{
			$email["custommessage"] .= "<b>Panel Login Password:</b> Use pre-existing password. <br /><br />";
		}

		//Make a call to Pterodactyl to grab all allocations for the new server
		$url = $params['serverhostname'].'/api/nodes/allocations/'. $params['serviceid'];
        $response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'GET');

        foreach($response['data']->allocations as $allocation)
        {
            $email["custommessage"] .= "<b>Server IP:</b> ".$allocation->ip.":".$allocation->port."<br />";
            if(isset($allocation->ip_alias))
            {
                $email["custommessage"] .= "<b>Server Alias:</b> ".$allocation->ip_alias.":".$allocation->port."<br />";
            }
        }
		
		$email["custommessage"] .= "</p>
									 <p>Thank you for choosing us.</p>";

		$email["custommessage"] = preg_replace( "/\r|\n/", "", $email["custommessage"] );

		$email["id"] = $params['serviceid'];
		localAPI("sendemail", $email, $adminid[0]);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Suspend an instance of a product/service.
 *
 * Called when a suspension is requested. This is invoked automatically by WHMCS
 * when a product becomes overdue on payment or can be called manually by admin
 * user.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function pterodactyl_SuspendAccount(array $params)
{
    try {
		$url = $params['serverhostname'].'/api/servers/'.$params['serviceid'].'/suspend';

		$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'POST');
		
		if($response['status_code'] != 204)
		{
			return $response['data']->message;
		}
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Un-suspend instance of a product/service.
 *
 * Called when an un-suspension is requested. This is invoked
 * automatically upon payment of an overdue invoice for a product, or
 * can be called manually by admin user.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function pterodactyl_UnsuspendAccount(array $params)
{
    try {
		$url = $params['serverhostname'].'/api/servers/'.$params['serviceid']."/unsuspend";

		$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'POST');
			
		if($response['status_code'] != 204)
		{
			return $response['data']->message;
		}
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Terminate instance of a product/service.
 *
 * Called when a termination is requested. This can be invoked automatically for
 * overdue products if enabled, or requested manually by an admin user.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function pterodactyl_TerminateAccount(array $params)
{
    try {
		$url = $params['serverhostname'].'/api/servers/'.$params['serviceid']."/force";

		$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'DELETE', $data);
		
		if($response['status_code'] != 204)
		{
			return $response['data']->message;
		}
		
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Change the password for an instance of a product/service.
 *
 * Called when a password change is requested. This can occur either due to a
 * client requesting it via the client area or an admin requesting it from the
 * admin side.
 *
 * This option is only available to client end users when the product is in an
 * active status.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function pterodactyl_ChangePassword(array $params)
{
    try {
		$url = $params['serverhostname'].'/api/users/'. $params['userid'];
		
		$data = array("password" => $params['password'] );

		$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'PATCH', $data);
		
		if($response['status_code'] != 200)
		{
			return $response['data']->message;
		}
		
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Upgrade or downgrade an instance of a product/service.
 *
 * Called to apply any change in product assignment or parameters. It
 * is called to provision upgrade or downgrade orders, as well as being
 * able to be invoked manually by an admin user.
 *
 * This same function is called for upgrades and downgrades of both
 * products and configurable options.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
 
function pterodactyl_ChangePackage(array $params)
{
    try {
		$url = $params['serverhostname'].'/api/servers/'. $params['serviceid'].'/build';

		$data = array("memory" => $params['configoption1'],
					  "swap" => $params['configoption2'],
					  "cpu" => $params['configoption3'],
					  "io" => $params['configoption4'],
					  "disk" => $params['configoption5'],
					  );

		$response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'PATCH', $data);
		
		if($response['status_code'] != 200)
		{
			return $response['data']->message;
		}		

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Admin services tab additional fields.
 *
 * Define additional rows and fields to be displayed in the admin area service
 * information and management page within the clients profile.
 *
 * Supports an unlimited number of additional field labels and content of any
 * type to output.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 * @see pterodactyl_AdminServicesTabFieldsSave()
 *
 * @return array
 */
function pterodactyl_AdminServicesTabFields(array $params)
{
    try {
        $response = array();

        return array(
            'Memory' => $params['configoption1']."mb",
            'Swap'   => $params['configoption2']."mb",
			'CPU'    => $params['configoption3']."%",
            'IO'     => $params['configoption4'],
		    'Disk'   => $params['configoption5']."mb",
            'Last Access Date' => date("Y-m-d H:i:s", $response['lastLoginTimestamp']),
        );
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        // In an error condition, simply return no additional fields to display.
    }

    return array();
}


/**
 * Client area output logic handling.
 *
 * This function is used to define module specific client area output. It should
 * return an array consisting of a template file and optional additional
 * template variables to make available to that template.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return array
 */
function pterodactyl_ClientArea(array $params)
{
    // Determine the requested action and set service call parameters based on
    // the action.
    $requestedAction = isset($_REQUEST['customAction']) ? $_REQUEST['customAction'] : '';

    $serviceAction = 'get_stats';
    $templateFile = 'templates/overview.tpl';

    try {
        $response = array();

        $url = $params['serverhostname'].'/api/nodes/allocations/'. $params['serviceid'];

        $response = pterodactyl_api_call($params['serverusername'], $params['serverpassword'], $url, 'GET');

        foreach($response['data']->allocations as $allocation)
        {
            $serverip['ip'][] = $allocation->ip.":".$allocation->port;
            if(isset($allocation->ip_alias))
            {
                $serverip['ip_alias'][] = $allocation->ip_alias.":".$allocation->port;
            }
        }
		
        return array(
            'tabOverviewReplacementTemplate' => $templateFile,
            'templateVariables' => array(
                'panelhostname' => $params['serverhostname'],
                'memory' => $params['configoption1']."mb",
				'swap' => $params['configoption2']."mb",
				'cpu' => $params['configoption3']."%",
				'io' => $params['configoption4'],
				'disk' => $params['configoption5']."mb",
				'email' => $params['clientsdetails']['email'],
				'server_ip' =>  $serverip['ip'],
				'server_alias' => $serverip['ip_alias'],
            ),
        );
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'provisioningmodule',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
    }
}