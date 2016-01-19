<?php
/*
Plugin Name: JE Paypal Express
Plugin URI: www.enginethemes.com
Description: JE Paypal Express Checkout.
Version: 1.0
Author: Engine Themes team
Author URI: www.enginethemes.com
Required: JobEngine version 2.3
License: GPL2
*/
require_once (dirname(__FILE__)."/lib/paypalfunctions.php");
require_once dirname(__FILE__) . '/update.php';

if(!defined('ET_DOMAIN') ) {
	class ET_PaypalExpress {
		
		protected $_test_mod;
		protected $_type;
		//protected $_order;
		/**
		# API_Signature:The Signature associated with the API user. which is generated by paypal.
		*/
		protected $_api_signature;
		/**
		# API user: The user that is identified as making the call. you can
		# also use your own API username that you created on PayPal?s sandbox
		# or the PayPal live site
		*/
		protected $_api_username;
		
		/**
		# API_password: The password associated with the API user
		# If you are using your own API username, enter the API password that
		# was generated by PayPal below
		# IMPORTANT - HAVING YOUR API PASSWORD INCLUDED IN THE MANNER IS NOT
		# SECURE, AND ITS ONLY BEING SHOWN THIS WAY FOR TESTING PURPOSES
		*/
		protected $_api_password;
		/**
		# Endpoint: this is the server URL which you have to connect for submitting your API request.
		*/
		protected $_api_endpoint;
		/**
		# Version: this is the API version in the request.
		# It is a mandatory parameter for each API request.
		# The only supported value at this time is 2.3
		*/
		protected $_version;
		/*
			PayPal URL. This is the URL that the buyer is
	 	    first sent to to authorize payment with their paypal account
		    change the URL depending if you are testing on the sandbox
		    or going to the live PayPal site
		    For the sandbox, the URL is
		    https://www.sandbox.paypal.com/webscr&cmd=_express-checkout&token=
		    For the live site, the URL is
		    https://www.paypal.com/webscr&cmd=_express-checkout&token=
		*/
		protected $_paypal_url;	
		protected $_dg_url ;
		protected $_proxy;
		protected $_proxy_host;
		protected $_proxy_port;
		
		function __construct( $mode = false ) {
			
			
			$api	=	self::get_api ();
			extract($api);
			// init api setting
			$this->_api_username	=	trim($api_username);
			$this->_api_password	=	trim($api_password);
			$this->_api_signature	=	trim($api_signature);
			
			$this->_api_endpoint	=	'https://api-3t.sandbox.paypal.com/nvp';
			$this->_version			=	87.0; 
			
			$this->_proxy			=	false;
			$this->_test_mod		=	get_option ('et_payment_mode', false) ;
			
			if ($this->_test_mod ) 
			{
				$this->_api_endpoint 	= "https://api-3t.sandbox.paypal.com/nvp";
				$this->_paypal_url		= "https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&token=";
				$this->_dg_url			 = "https://www.sandbox.paypal.com/incontext?token=";
			}
			else
			{
				$this->_api_endpoint	 = "https://api-3t.paypal.com/nvp";
				$this->_paypal_url		 = "https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=";
				$this->_dg_url			 = "https://www.paypal.com/incontext?token=";
			}

		}
		
		function nvp_header(){
			
			$API_Endpoint	=	$this->_api_endpoint;
			$API_UserName	=	$this->_api_username;
			$API_Password	=	$this->_api_password;
			$API_Signature	=	$this->_api_signature;
			$nvp_Header		=	'';
			$subject		=	'';
			$AUTH_token		=	'';
			$AUTH_signature	=	'';
			$AUTH_timestamp	=	'';
			$version		=	 $this->_version;
			
			$nvpHeaderStr = "";
			
			if(defined('AUTH_MODE')) {
				//$AuthMode = "3TOKEN"; //Merchant's API 3-TOKEN Credential is required to make API Call.
				//$AuthMode = "FIRSTPARTY"; //Only merchant Email is required to make EC Calls.
				//$AuthMode = "THIRDPARTY";Partner's API Credential and Merchant Email as Subject are required.
				$AuthMode = "AUTH_MODE"; 
			} 
			else {
				
				if((!empty($API_UserName)) && (!empty($API_Password)) && (!empty($API_Signature)) && (!empty($subject))) {
					$AuthMode = "THIRDPARTY";
				}
				
				else if((!empty($API_UserName)) && (!empty($API_Password)) && (!empty($API_Signature))) {
					$AuthMode = "3TOKEN";
				}
				
				elseif (!empty($AUTH_token) && !empty($AUTH_signature) && !empty($AUTH_timestamp)) {
					$AuthMode = "PERMISSION";
				}
			    elseif(!empty($subject)) {
					$AuthMode = "FIRSTPARTY";
				}
			}
			switch($AuthMode) {
				
				case "3TOKEN" : 
						$nvpHeaderStr = "&PWD=".urlencode($API_Password)."&USER=".urlencode($API_UserName)."&SIGNATURE=".urlencode($API_Signature);
						break;
				case "FIRSTPARTY" :
						$nvpHeaderStr = "&SUBJECT=".urlencode($subject);
						break;
				case "THIRDPARTY" :
						$nvpHeaderStr = "&PWD=".urlencode($API_Password)."&USER=".urlencode($API_UserName)."&SIGNATURE=".urlencode($API_Signature)."&SUBJECT=".urlencode($subject);
						break;		
				case "PERMISSION" :
					    $nvpHeaderStr = formAutorization($AUTH_token,$AUTH_signature,$AUTH_timestamp);
					    break;
			}
				return $nvpHeaderStr;
		}

		/**
		  * hash_call: Function to perform the API call to PayPal using API signature
		  * @methodName is name of API  method.
		  * @nvpStr is nvp string.
		  * returns an associtive array containing the response from the server.
		*/
		function hash_call($methodName,$nvpStr) {
			//declaring of global variables
			$API_Endpoint	=	$this->_api_endpoint; 
			$API_UserName	=	$this->_api_username;
			$API_Password	=	$this->_api_password ;
			$API_Signature	=	$this->_api_signature;
			$version		=	$this->_version ;
			$USE_PROXY		=	false ;
			$PROXY_HOST		=	$this->_proxy_host;
			$PROXY_PORT		=	$this->_proxy_port;
			
			$sBNCode = "PP-ECWizard";

			//setting the curl parameters.
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$API_Endpoint);
			curl_setopt($ch, CURLOPT_VERBOSE, 1);

			//turning off the server and peer verification(TrustManager Concept).
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

			curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
			curl_setopt($ch, CURLOPT_POST, 1);
			
		    //if USE_PROXY constant set to TRUE in Constants.php, then only proxy will be enabled.
		   //Set proxy name to PROXY_HOST and port number to PROXY_PORT in constants.php 
			if($USE_PROXY)
				curl_setopt ($ch, CURLOPT_PROXY, $PROXY_HOST. ":" . $PROXY_PORT); 

			//NVPRequest for submitting to server
			$nvpreq="METHOD=" . urlencode($methodName) . "&VERSION=" . urlencode($version) . "&PWD=" . urlencode($API_Password) . "&USER=" . urlencode($API_UserName) . "&SIGNATURE=" . urlencode($API_Signature) . $nvpStr . "&BUTTONSOURCE=" . urlencode($sBNCode);

			//setting the nvpreq as POST FIELD to curl
			curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

			//getting response from server
			$response = curl_exec($ch);
			
			//convrting NVPResponse to an Associative Array
			$nvpResArray	=	$this->deformatNVP($response);
			$nvpReqArray	=	$this->deformatNVP($nvpreq);
			$_SESSION['nvpReqArray']	=	$nvpReqArray;

			if (curl_errno($ch)) 
			{
				// moving to display page to display curl errors
				  $_SESSION['curl_error_no']=curl_errno($ch) ;
				  $_SESSION['curl_error_msg']=curl_error($ch);

				  //Execute the Error handling module to display errors. 
			} 
			else 
			{
				 //closing the curl
			  	curl_close($ch);
			}

			return $nvpResArray;
		}

		/** 
		 * This function will take NVPString and convert it to an Associative Array and it will decode the response.
		 * It is usefull to search for a particular key and displaying arrays.
		 * @nvpstr is NVPString.
		 * @nvpArray is Associative Array.
		*/

		function deformatNVP($nvpstr)
		{
			$intial=0;
		 	$nvpArray = array();

			while(strlen($nvpstr)) {
				//postion of Key
				$keypos= strpos($nvpstr,'=');
				//position of value
				$valuepos = strpos($nvpstr,'&') ? strpos($nvpstr,'&'): strlen($nvpstr);

				/*getting the Key and Value values and storing in a Associative Array*/
				$keyval=substr($nvpstr,$intial,$keypos);
				$valval=substr($nvpstr,$keypos+1,$valuepos-$keypos-1);
				//decoding the respose
				$nvpArray[urldecode($keyval)] =urldecode( $valval);
				$nvpstr=substr($nvpstr,$valuepos+1,strlen($nvpstr));
		    }

			return $nvpArray;

		}

		function form_autorization($auth_token,$auth_signature,$auth_timestamp){
			
			$authString="token=".$auth_token.",signature=".$auth_signature.",timestamp=".$auth_timestamp ;
			return $authString;
			
		}
		/**
		 * save paypal setting
		 * @param $settings : array ()
		 * 	- cmd   : kind of ET_Payment button 
		 *  - charset : setting character set
		 *  - total : amount
		 *  - submit : submit button text
		 *  - form  : 
		 *  - cbt :  
		 *  - ipn : notify check
		 *  - currency 
		 *  - return : url is redirected after ET_Payment succesful
		 *  - notify_url : url will be redirected if have notification
		 *  - cancel_return : url will be redirected when buyer cancel ET_Payment
		 *  - test_mod : test mod for developer test with sanbox account
		 *  - test_mail : mail address receives notify email in test mod
		 * @see ET_Payment::set_settings()
		 */
		/*
		function set_settings( $settings = array () ) {
		
		}
		*/
		
		/**
		 * @return settings array 
		 * @see ET_Payment::get_settings()
		 */
		function get_settings( ) {
			return $this->_settings;
		}
		/**
		 * get paypal checkout url
		 * return string : url
		 */
		function get_paypal_url () {
			return $this->_paypal_url;
		}
		
		static function set_api ( $api = array () ) {
			update_option('et_paypal_express_api', $api );
			if(!self::is_enable()) {
				ET_Payment::disable_gateway('je_ppexpress');
				return __('Api setting invalid', ET_DOMAIN);
			}
			return true;
		}
		
		static function get_api () {
			
			$api	= (array)get_option('et_paypal_express_api', true);
			if(!isset($api['api_username'])) $api['api_username']	=	'';
			if(!isset($api['api_password'])) $api['api_password']	=	'';
			if(!isset($api['api_signature'])) $api['api_signature']	=	'';
			return $api;
		}
		
		// function accept visitor
		function accept ( ET_PaymentVisitor $visitor ) {
			$visitor->visitPaypal($this);
		}
		
		public function is_enable() {
			if($this->_api_username && $this->_api_password && $this->_api_signature )
				return true;
			return false;
		}	

		function RedirectToPayPalDG ( $token )
		{			
			// Redirect to paypal.com here
			$payPalURL = $this->_dg_url . $token;
			header("Location: ".$payPalURL);
			exit;
		}	

	}


	class JE_PPExpressVisitor {

		/*   
		'-------------------------------------------------------------------------------------------------------------------------------------------
		' Purpose: 	Prepares the parameters for the SetExpressCheckout API Call for a Digital Goods payment.
		' Inputs:  
		'		paymentAmount:  	Total value of the shopping cart
		'		currencyCodeType: 	Currency code value the PayPal API
		'		paymentType: 		paymentType has to be one of the following values: Sale or Order or Authorization
		'		returnURL:			the page where buyers return to after they are done with the payment review on PayPal
		'		cancelURL:			the page where buyers return to when they cancel the payment review on PayPal
		'--------------------------------------------------------------------------------------------------------------------------------------------	
		*/
		function SetExpressCheckoutDG( $order ) {
			//------------------------------------------------------------------------------------------------------------------------------------
			// Construct the parameter string that describes the SetExpressCheckout API call in the shortcut implementatio
			
			

			$payment	=	new ET_PaypalExpress (ET_Payment::get_payment_test_mode());
			
			$paymentType		=	'sale';
			$paymentAmount		=	$order['total'];
			$currencyCodeType	=	$order['currencyCodeType'];
			$returnURL			=	et_get_page_link('process-payment' , array('paymentType' => 'je_ppexpress' ) );
			$cancelURL			=	et_get_page_link('process-payment' , array('paymentType' => 'je_ppexpress' ) );
			
			$products			=	$order['products'];

			if($order['total'] < $order['total_before_discount']) {
				$products[]		=	array( 'NAME' => __("Discount", ET_DOMAIN) , 'QTY' => '1' , 'AMT' => ( $order['total'] - $order['total_before_discount'] ) ) ;
			}
			
			

			$nvpstr = "&PAYMENTREQUEST_0_AMT=". $paymentAmount;
			$nvpstr .= "&PAYMENTREQUEST_0_PAYMENTACTION=" . $paymentType;
			$nvpstr .= "&RETURNURL=" . $returnURL;
			$nvpstr .= "&CANCELURL=" . $cancelURL;
			$nvpstr .= "&PAYMENTREQUEST_0_CURRENCYCODE=" . $currencyCodeType;
			$nvpstr .= "&REQCONFIRMSHIPPING=0";
			$nvpstr .= "&NOSHIPPING=1";

			$index	=	0;
			foreach($products as $key => $item) {
			
				$nvpstr .= "&L_PAYMENTREQUEST_0_NAME" . $index . "=" . urlencode($item["NAME"]);
				$nvpstr .= "&L_PAYMENTREQUEST_0_AMT" . $index . "=" . urlencode($item['AMT']);
				$nvpstr .= "&L_PAYMENTREQUEST_0_QTY" . $index . "=" . urlencode($item["QTY"]);
				$nvpstr .= "&L_PAYMENTREQUEST_0_ITEMCATEGORY" . $index . "=Digital";
				$index++;

			}
			
			
			//'--------------------------------------------------------------------------------------------------------------- 
			//' Make the API call to PayPal
			//' If the API call succeded, then redirect the buyer to PayPal to begin to authorize payment.  
			//' If an error occured, show the resulting errors
			//'---------------------------------------------------------------------------------------------------------------
		    $resArray = $payment->hash_call("SetExpressCheckout", $nvpstr);
			$ack = strtoupper($resArray["ACK"]);
			if($ack == "SUCCESS" || $ack == "SUCCESSWITHWARNING")
			{
				$token = urldecode($resArray["TOKEN"]);
				$_SESSION['TOKEN'] = $token;
				//$payment->RedirectToPayPalDG ($token);
			}
			   
			return $resArray;
		}

		/*
		'-------------------------------------------------------------------------------------------------------------------------------------------
		' Purpose: 	Prepares the parameters for the GetExpressCheckoutDetails API Call.
		'
		' Inputs:  
		'		sBNCode:	The BN code used by PayPal to track the transactions from a given shopping cart.
		' Returns: 
		'		The NVP Collection object of the GetExpressCheckoutDetails Call Response.
		'--------------------------------------------------------------------------------------------------------------------------------------------	
		*/
		function ConfirmPayment( $token, $payerID, $order ) {
			/* Gather the information to make the final call to
			   finalize the PayPal payment.  The variable nvpstr
			   holds the name value pairs
			   */
			
			$token 				= 	urlencode($token);
			$payment			=	new ET_PaypalExpress ( ET_Payment::get_payment_test_mode() );

			$paymentType		=	urlencode('sale');
			$currencyCodeType	=	urlencode($order['currencyCodeType']);
			$FinalPaymentAmt	=	$order['total'];
			
			$procducts			=	$order['products'];

			$product			=	get_post_meta( $order['ID'], 'et_order_products' , true);

			$payerID 			= urlencode($payerID);
			$serverName 		= urlencode($_SERVER['SERVER_NAME']);

			$nvpstr  = '&TOKEN=' . $token . '&PAYERID=' . $payerID . '&PAYMENTREQUEST_0_PAYMENTACTION=' . $paymentType . '&PAYMENTREQUEST_0_AMT=' . $FinalPaymentAmt;
			$nvpstr .= '&PAYMENTREQUEST_0_CURRENCYCODE=' . $currencyCodeType . '&IPADDRESS=' . $serverName; 
			
			$index	=	0;
			foreach($procducts as $key => $item) {
			
				$nvpstr .= "&L_PAYMENTREQUEST_0_NAME" . $index . "=" . urlencode($item["NAME"]);
				$nvpstr .= "&L_PAYMENTREQUEST_0_AMT" . $index . "=" . urlencode($order['total']);
				$nvpstr .= "&L_PAYMENTREQUEST_0_QTY" . $index . "=" . urlencode($item["QTY"]);
				$nvpstr .= "&L_PAYMENTREQUEST_0_ITEMCATEGORY" . $index . "=Digital";
				$index++;
			}
			 /* Make the call to PayPal to finalize payment
			    If an error occured, show the resulting errors
			    */
			$resArray= $payment->hash_call("DoExpressCheckoutPayment",$nvpstr);

			/* Display the API response back to the browser.
			   If the response from PayPal was a success, display the response parameters'
			   If the response was an error, display the errors received using APIError.php.
			   */
			$ack = strtoupper($resArray["ACK"]);

			return $resArray;
		}

	}

	class JE_PPExpress extends ET_PaypalExpress {
		function __construct () {
			parent::__construct ( );

			add_action( 'after_je_payment_button', array( $this, 'payment_button' ) );
			add_action ('je_payment_settings', array ($this, 'ppexpress_setting'));

			add_filter ('et_update_payment_setting', array($this, 'set_settings' ), 10 ,3);
			add_filter( 'et_support_payment_gateway',array($this,'et_support_payment_gateway' ));

			add_filter ('et_enable_gateway', array($this,'et_enable_ppexpress'), 10 , 2);

			add_action('wp_footer' , array($this, 'frontend_js'));

			add_filter ('je_payment_setup', array($this, 'setup_payment'), 10, 3);
			add_filter( 'je_payment_process', array($this, 'process_payment'), 10 , 3 );
		}

		function setup_payment ( $response , $paymentType, $order ) {
			if( $paymentType == 'JE_PPEXPRESS') {
				$order_pay	=	$order->generate_data_to_pay (); 
				
				$checkout	=	new JE_PPExpressVisitor ();
				$response	=	$checkout->SetExpressCheckoutDG ( $order_pay );
				$ack		=	strtoupper($response['ACK']);
				if($ack == "SUCCESS" || $ack == "SUCCESSWITHWARNING") {
					$token 				= urldecode($response["TOKEN"]);
                 	$response['url']	=	$this->_dg_url.$token;
				}
			}

			return $response;
		}

		function process_payment ( $payment_return, $order , $payment_type) {

			if($payment_type == 'je_ppexpress' ) {
				$ack	=	false;
				if(isset($_REQUEST['token']) && isset($_REQUEST['PayerID']) ) {
					$token		=	$_REQUEST['token'];
					$payerID	=	$_REQUEST['PayerID'];

					$checkout	=	new JE_PPExpressVisitor ();
					$order_pay	=	$order->generate_data_to_pay (); 
					$response	=	$checkout->ConfirmPayment ($token, $payerID , $order_pay );
					$ack		=	strtoupper($response['ACK']);
					if($ack == "SUCCESS" || $ack == "SUCCESSWITHWARNING") {
	                 	$payment_return	=	array (
							'ACK' 		=> true,
							'payment'	=>	'je_ppexpress'
						);
						$order->set_payment_code ($token);
						$order->set_payer_id ($payerID);
						$order->set_status ('publish');
						$order->update_order();
						$session	=	et_read_session();
						$post	=	get_post ($session['job_id']);
						if($post->post_type == 'job' )
							$link	=	get_permalink( $session['job_id'] ) ;
						elseif(isset($session['resume_id']) )  
							$link	=	get_permalink( $session['resume_id'] ) ; 
						else
							$link	=	get_post_type_archive_link( 'resume' );
						
					?>
						<script type="text/javascript">
							setTimeout (function () {
								if (window.opener) {
									window.opener.location.href = '<?php echo $link  ?>';
									window.close();
								} }, 3000 );
						</script>
						<?php 
						$ack	= true;
					}


				}

				if(!$ack) {
				?>
					<script type="text/javascript">
					setTimeout (function () {
						if (window.opener) {
							window.opener.location.reload();
							window.close();
						} }, 3000 );
					</script>
				<?php 
				}
				?>
				<style>
					.redirect-content {
						position: absolute;
						left : 100px;
					}
					.main-center {
						margin: 0 auto;
						width: auto !important;
					}

				</style>
				<?php
			}	
			return $payment_return;
		}

		function frontend_js () {
			if(is_page_template('page-post-a-job.php') || is_page_template( 'page-upgrade-account.php' )) {
				wp_enqueue_script('ppexpress.checkout', 'https://www.paypalobjects.com/js/external/dg.js', array('jquery'));
				wp_enqueue_script('ppexpress', plugin_dir_url( __FILE__).'/ppexpress.js', array('jquery'));
			}
		}

		function et_enable_ppexpress ($available, $gateway) {
			if($gateway == 'je_ppexpress') {
				if($this->is_enable ()) return true; 
				return false;
			}
			return $available;
		}

		function payment_button ($payment_gateways) {
			if(!isset($payment_gateways['je_ppexpress']))  return;
			$ppExpress	=	$payment_gateways['je_ppexpress'];
			if( !isset($ppExpress['active']) || $ppExpress['active'] == -1) return ;
		?>
			<li class="clearfix" id="ppexpress_checkout">
				<form action="" method="post" id="ppexpress_form">
					<div class="f-left">
						<div class="title"><?php _e( 'Paypal Express', ET_DOMAIN )?></div>
						<div class="desc"><?php _e( 'Pay using your credit card through Paypal Express.', ET_DOMAIN )?></div>
					</div>
					<div class="btn-select f-right">
						<button id="je_ppexpress" class="bg-btn-hyperlink border-radius" data-gateway="je_ppexpress" ><?php _e('Select', ET_DOMAIN );?></button>
					</div>
				</form>
			</li>
				

		<?php
		}

		// add stripe to je support payment
		function et_support_payment_gateway ( $gateway ) {
			$gateway['je_ppexpress']	=	array (
										'label' 		=> __("Paypal Express",ET_DOMAIN),  
										'description'	=> __("Send your payment through Paypal Express", ET_DOMAIN),
										'active' 		=> -1
										);
			return $gateway;
		}

		/**
		 * render stripe settings form
		*/
		function ppexpress_setting () {
			$api = self::get_api();
		?>
			<div class="item">
				<div class="payment">
					<a class="icon" data-icon="y" href="#"></a>
					<div class="button-enable font-quicksand">
						<?php et_display_enable_disable_button('je_ppexpress', 'Paypal Express')?>
					</div>
					<span class="message"></span>
					<?php _e("Paypal Express",ET_DOMAIN);?>
				</div>
				<div class="form payment-setting">
					<div class="form-item">
						<div class="label">
							<?php _e("Your API username",ET_DOMAIN);?> 
						</div>
						<input class="bg-grey-input <?php if($api['api_username'] == '') echo 'color-error' ?>" name="ppexpress_username" type="text" value="<?php echo $api['api_username']  ?> " />
						<span class="icon <?php if($api['api_username'] == '') echo 'color-error' ?>" data-icon="<?php  data_icon($api['api_username']) ?>"></span>
					</div>
					<div class="form-item">
						<div class="label">
							<?php _e("Your API password",ET_DOMAIN);?>
							
						</div>
						<input class="bg-grey-input <?php if($api['api_password'] == '') echo 'color-error' ?>" type="text" name="ppexpress_password" value="<?php echo $api['api_password'] ?> " />
						<span class="icon <?php if($api['api_password'] == '') echo 'color-error' ?>" data-icon="<?php  data_icon($api['api_password']) ?>"></span>
					</div>

					<div class="form-item">
						<div class="label">
							<?php _e("Your API signature",ET_DOMAIN);?>
							
						</div>
						<input class="bg-grey-input <?php if($api['api_signature'] == '') echo 'color-error' ?>" type="text" name="ppexpress_signature" value="<?php echo $api['api_signature'] ?> " />
						<span class="icon <?php if($api['api_signature'] == '') echo 'color-error' ?>" data-icon="<?php  data_icon($api['api_signature']) ?>"></span>
					</div>
				</div>
			</div>
		<?php 
		}

		/**
		 * ajax callback to update payment settings
		*/
		function set_settings ( $msg , $name, $value ) {
			$api	=	self::get_api();
			switch ($name) {
				case 'PPEXPRESS_SIGNATURE':
					$api['api_signature']	=	trim($value);
					$msg	=	self::set_api( $api );
					break;
				case 'PPEXPRESS_USERNAME':
					$api['api_username']	=	trim($value);
					$msg	=	self::set_api( $api );
					break;
				case 'PPEXPRESS_PASSWORD':
					$api['api_password']	=	trim($value);
					$msg	=	self::set_api( $api );
					break;
			}

			return $msg;
		}

		

	}

	new JE_PPExpress ();
}

