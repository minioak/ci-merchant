<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/*
 * CI-Merchant Library
 *
 * Copyright (c) 2011-2012 Crescendo Multimedia Ltd
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

/**
 * Merchant Cardsave Class
 *
 * Payment processing using Cardsave.net Transparent
 * Documentation: https://mms.cardsaveonlinepayments.com/Pages/PublicPages/TransparentRedirect.aspx
 */

class Merchant_cardsave_transparent extends Merchant_driver
{
	const PROCESS_URL = 'https://mms.cardsaveonlinepayments.com/Pages/PublicPages/TransparentRedirect.aspx';

	public function default_settings()
	{
		return array(
			'username' => '',
			'password' => '',
			'preshared_key' => ''
		);
	}

	public function purchase()
	{
		return $this->_generate_request('SALE');
	}

	public function purchase_return()
	{
		// Keep the flashdata around if we're asked to do it
		if ($this->param('keep_flashdata'))
		{
			$this->CI->session->keep_flashdata($this->param('keep_flashdata'));
		}
				
		// Check to see if this is a 3DSECURE result
		if ($this->CI->input->post('PaRes'))
		{
			$request = $this->_generate_request('3DSECURE');
			$this->post_redirect($request['url'], $request['data']);
			return;
		}
		
		// Validate the response
		if ($this->_confirm_hash_digest())
		{
			switch ($this->CI->input->post('StatusCode'))
			{
				case 0: // Completed
					return new Merchant_cardsave_transparent_response($_POST);
				case 3: // 3DSecure Required
					return array(
						'data' => array(
							'acs_url'		=>	$this->CI->input->post('ACSURL'),
							'pa_req'		=>	$this->CI->input->post('PaREQ'),
							'cross_ref'		=>	$this->CI->input->post('CrossReference'),
							'callback_url'	=>	$this->param('callback_url')
						)
					);
				case 4: // Payment referred
				case 5: // Payment declined
				case 20: // Duplicate transaction
				case 30: // Exception occurred
					return new Merchant_cardsave_transparent_response($_POST);
				default: // Unknown response
					return new Merchant_cardsave_transparent_response($_POST);
			}
		}
		
		return new Merchant_cardsave_transparent_response($_POST);
	}

	private function _process_url()
	{
		return self::PROCESS_URL;
	}
	
	private function _generate_request($method)
	{
		$static_data = array();
		
		switch ($method)
		{
			case '3DSECURE':
				$static_data = array(
					'PreSharedKey'			=> $this->settings['preshared_key'],
					'MerchantID'			=> $this->settings['username'],
					'Password'				=> $this->settings['password'],
					'CrossReference'		=> $this->CI->input->post('MD'),
					'TransactionDateTime'	=> $this->_gateway_timestamp(),
					'CallbackURL'			=> $this->param('callback_url'),
					'PaRES'					=> $this->CI->input->post('PaRes')
				);
				break;
			case 'SALE':
			default:
				$static_data = array(
					'PreSharedKey'			=> $this->settings['preshared_key'],
					'MerchantID'			=> $this->settings['username'],
					'Password'				=> $this->settings['password'],
					'Amount'				=> $this->param('amount') * 100,
					'CurrencyCode' 			=> $this->currency_numeric(),
					'OrderID'				=> $this->_order_guid(),
					'TransactionType'		=> $this->param('method'),
					'TransactionDateTime'	=> $this->_gateway_timestamp(),
					'CallbackURL'			=> $this->param('callback_url'),
					'OrderDescription'		=> $this->param('description')
				);
				break;
		}
		
		$static_data['HashDigest'] = $this->_calculate_hash($static_data);
		
		$static_data['EchoCardType'] = 'true';
		$static_data['EchoCV2CheckResult'] = 'true';
		$static_data['EchoAVSCheckResult'] = 'true';
		$static_data['EchoThreeDSecureAuthenticationCheckResult'] = 'true';
		
		unset($static_data['PreSharedKey']);
		unset($static_data['Password']);
		
		return array(
			'url' => $this->_process_url(),
			'data' => $static_data
		);
	}
	
	private function _confirm_hash_digest()
	{
		$static_data = array();
		
		switch ($this->CI->input->post('StatusCode'))
		{
		 	case 3: // 3DSecure required input hash
			 	$static_data = array(
					'PreSharedKey'			=> $this->settings['preshared_key'],
					'MerchantID'			=> $this->settings['username'],
					'Password'				=> $this->settings['password'],
					'StatusCode'			=> $this->CI->input->post('StatusCode'),
					'Message'				=> $this->CI->input->post('Message'),
					'CrossReference'		=> $this->CI->input->post('CrossReference'),
					'OrderID'				=> $this->CI->input->post('OrderID'),
					'TransactionDateTime'	=> $this->CI->input->post('TransactionDateTime'),
					'ACSURL'				=> $this->CI->input->post('ACSURL'),
					'PaREQ'					=> $this->CI->input->post('PaREQ')
				);
		 		break;
		 	default: // Payment completed hash
			 	$static_data = array(
					'PreSharedKey'			=> $this->settings['preshared_key'],
					'MerchantID'			=> $this->settings['username'],
					'Password'				=> $this->settings['password'],
					'Amount'				=> $this->CI->input->post('Amount'),
					'CurrencyCode' 			=> $this->currency_numeric(),
					'OrderID'				=> $this->CI->input->post('OrderID'),
					'TransactionType'		=> $this->CI->input->post('TransactionType'),
					'TransactionDateTime'	=> $this->CI->input->post('TransactionDateTime'),
					'OrderDescription'		=> $this->CI->input->post('OrderDescription')
				);
		 		break;
		}
		
		return ($this->_calculate_hash($static_data) == $this->CI->input->post('HashDigest'));
	}
	
	private function _calculate_hash($static_data)
	{
		$hash_string = '';
		foreach ($static_data as $key => $value)
		{
			$hash_string = $hash_string . $key . '=' . $value . '&';
		}
		return sha1(rtrim($hash_string, '&'));
	}
	
	private function _gateway_timestamp()
	{
		return date('Y-m-d H:i:s O');
	}
	
	private function _order_guid()
	{
		if ($this->CI->input->post('order_ref'))
		{
			return $this->CI->input->post('order_ref');
		}
		else
		{
			mt_srand((double)microtime()*10000);//optional for php 4.2.0 and up.
			$charid = strtoupper(md5(uniqid(rand(), true)));
			$hyphen = chr(45);// "-"
			$uuid = substr($charid, 0, 8).$hyphen
					.substr($charid, 8, 4).$hyphen
					.substr($charid,12, 4).$hyphen
					.substr($charid,16, 4).$hyphen
					.substr($charid,20,12);
			return $uuid;
		}
	}

}

class Merchant_cardsave_transparent_response extends Merchant_response
{
	protected $_response;
	protected $_allow_retry;

	public function __construct($response)
	{
		$this->_response = $response;

		$this->_status = self::FAILED;
		$this->_allow_retry = FALSE;
		$this->_message = $this->_response['Message'];
		
		$this->_reference = implode(',', array($this->_response['AddressNumericCheckResult'],
				$this->_response['PostCodeCheckResult'],
				$this->_response['CV2CheckResult'],
				$this->_response['ThreeDSecureCheckResult']));

		$this->_reference = implode(';', array($this->_response['OrderID'], $this->_reference));
		
		if ($this->_response['StatusCode'] == '0')
		{		
			$this->_status = self::COMPLETE;
		}
		else
		{
			// Apply a friendlier error message
			if (strlen($this->_response['CV2CheckResult']) > 0 && strtoupper($this->_response['CV2CheckResult']) != 'PASSED' 
				&& strtoupper($this->_response['CV2CheckResult']) != 'NOT_CHECKED')
			{
				$this->_allow_retry = TRUE;
				$this->_message = 'Your card details were invalid. Please try again';
			}
			else if (strlen($this->_response['PostCodeCheckResult']) > 0 && strtoupper($this->_response['PostCodeCheckResult']) != 'PASSED' 
				&& strtoupper($this->_response['PostCodeCheckResult']) != 'NOT_CHECKED')
			{
				$this->_allow_retry = TRUE;
				$this->_message = 'Your postcode did not match the address on file for your card. Please try again';
			}
			else if (strlen($this->_response['AddressNumericCheckResult']) > 0 && strtoupper($this->_response['AddressNumericCheckResult']) != 'PASSED' 
				&& strtoupper($this->_response['AddressNumericCheckResult']) != 'NOT_CHECKED')
			{
				$this->_allow_retry = TRUE;
				$this->_message = 'Your address details did not match the ones on file for your card. Please try again';
			}
		}
	}
	
	public function allow_retry()
	{
		return $this->_allow_retry;
	}

}

/* End of file ./libraries/merchant/drivers/merchant_cardsave_transparent.php */