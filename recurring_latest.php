<?php
ini_set('display_errors', 1);
//ini_set('memory_limit', "512M");
error_reporting(E_ALL ^ E_NOTICE);
//set_time_limit(3600);

/* Include the config file */
include '/var/www/html/app/config/config.php';
/* import wirecard class */
include '/var/www/html/app/vendors/wirecard/wirecard.php';
/* import wirecard 3D secure class */
include '/var/www/html/app/vendors/wirecard/wirecard_maestro.php';


$Pay = new RecurringPayment();

$casenumber = !empty($argv[1]) ? $argv[1] : 1; 
$batchid = !empty($argv[2]) ? $argv[2] : 1;
$totallimit = !empty($argv[3]) ? $argv[3] : 0;
//echo $totalLimit,PHP_EOL;

$Pay->doTransactions();

$Pay->output .= var_export($Pay->statsArray, true);

$query = "INSERT INTO pay_recurring_output (output, created, modified) VALUES ('".addslashes($Pay->output)."', '".date("Y-m-d H:i:s")."', '".date("Y-m-d H:i:s")."')";
$Pay->db_query($query);
//echo "<pre>".print_r($Pay->statsArray, true)."</pre>";
//exec("/usr/bin/php /var/www/html/app/webroot/no_gzip/send_recurring_receipt.php");
class RecurringPayment {
	
	var $output					= NULL;
	var $db						= NULL;
	var $amount					= '';
	var $currency				= '';
	var $usage					= '';
	var $countryId				= '';
	var $trialDays				= '';
	var $billingDays			= '';
	var $subscriptionTypeId		= '';
	var $paidArray 				= array();
	var $statsArray 			= array();
	var $chargelist 			= '';
	
	const ACTION_FAIL 			= 99;
	const ACTION_FAIL_MSG 		= 'Payment failed';
	const ERROR_STATE_1_DAYS	= 3;
	const ERROR_STATE_2_DAYS	= 1;
	const ACTION_CONVERT 		= 333;
	const ACTION_CONVERT_MSG	= 'Convert the user at the next collection';
	const ACTION_SUCCESS 		= 100;
	const ACTION_SUCCESS_MSG	= 'Payment success';
	const ACTION_DISABLE		= 98;
	const ACTION_DISABLE_MSG	= 'Membership disabled';
	const RETAIN_CARD			= 'Retain card.';
	const INVALID_CARD			= 'Invalid card.';
	const LOST_CARD				= 'Lost card.';
	const RESTRICTED_CARD		= 'Restricted card';
	const EXPIRED_CARD			= 'Card expired.';
	const STOLEN_CARD			= 'Stolen Card, pick up.';
	const NO_PROCESSING			= 'Processing temporarily not possible.';
	const INVALID_TRANSACTION	= 'Invalid transaction flow';
	const CVC_INVALID			= 'CVC number invalid';
	const RECENT_PAYMENT_DAYS	= 5;
	const SECURE_3D				= "Secure3D";
	const SECURE_3D2			= "secure3d";
	const WRONG_REFERENCE		= "Could not find referenced transaction for GuWID";
	const EXPIRATION_DATE_INVALID = "Expiration date invalid";
	
	function __construct() {
	}

	function setCountryId($countryId) {
		$this->countryId = $countryId;
	}
	
	function getCountryId() {
		return $this->countryId;
	}
	
	function setAmount($amount) {
		$this->amount = $amount;
	}
	
	function getAmount() {
		return $this->amount;
	}
	
	function setCurrency($currency) {
		$this->currency = $currency;
	}
	
	function getCurrency() {
		return $this->currency;
	}
	
	function setTrialDays($trialDays) {
		$this->trialDays = $trialDays;
	}
	
	function getTrialDays() {
		return $this->trialDays;
	}
	
	function setBillingDays($billingDays) {
		$this->billingDays = $billingDays;
	}
	
	function getBillingDays() {
		return $this->billingDays;
	}
	
	function setSubscriptionTypeId($subscriptionTypeId) {
		$this->subscriptionTypeId = $subscriptionTypeId;
	}
	
	function getSubscriptionTypeId() {
		return $this->subscriptionTypeId;
	}
	
	public function db_query($query) {
		global $config, $db;
		// Configure::read('Database.host');
		// Make a connection
		if (!$db = @mysql_connect($config['Database']['host'], $config['Database']['login'], $config['Database']['password'])) {
			die('Unable to connect to the database.');
		}
		
		// Select database
		if (!@mysql_select_db($config['Database']['database'], $db)) {
			die("Unable to find the database");
		}
		
		mysql_query('SET SESSION wait_timeout = 120');
		
		mysql_query('SET SESSION group_concat_max_len = 10485760');
		
		$result = mysql_query($query) or die (mysql_error());
		
		return $result;
	}
	
	public function doTransactions() {
		$this->extendPlus();			
	}
	
	protected function extendPlus() {
		global $casenumber, $batchid, $totallimit;
		$limiter = "";
		if (!empty($totallimit)) {
			 $limiter = " LIMIT $totallimit";
		}
		$s = "SELECT * FROM subscription_festival_charges WHERE case_number='$casenumber' AND batch_id='$batchid' AND (split_1_current_state = 0 OR split_2_current_state = 0) AND gateway_id != 3 $limiter";
		$q = $this->db_query($s);
		//echo $s, PHP_EOL;
		if (!$q) {
			return FALSE;
		}
		
		while ($r = mysql_fetch_object($q)) {
			
			//echo print_r($r);
			
			echo ++$i.'-'.$r->user_id.' -> '.$r->subscription_type_id.'-->TOTAL AMOUNT DUE: '.$r->total_amount_due.'-->CURRENCY:'.$r->base_currency, PHP_EOL;
			
			//hasPaidCancellation
			$paidCancellation = $this->userPaidCancellation($r->user_id);
			
			//isActive
			$isActive = $this->isActive($r->user_id);
			
			//hasOverdue
			$hasOverdue = $this->hasOverdue($r->user_id);
			
			//Insert attempt here
			if(!$this->recentPayment21Days($r->user_id) && !$paidCancellation && $isActive && !$hasOverdue) {
				if ($r->split_1_current_state == 0) {
					if ($this->doTransaction($r, 1)) {
						$this->insertAttempt($r->id, 1);
						if ($r->split_2_current_state == 0) {
							$this->doTransaction($r, 2);
							$this->insertAttempt($r->id,2);
						}
					}
				}
				else {
					if ($r->split_2_current_state == 0) {
						$this->doTransaction($r, 2);
						$this->insertAttempt($r->id,2);
					}
				}	
			}
			if ($paidCancellation || !$isActive || $hasOverdue) {
				//update cancellation
				if ($paidCancellation) {
					echo ++$i.'-'.$r->user_id.' -> '.$r->subscription_type_id.'-->TOTAL AMOUNT DUE: '.$r->total_amount_due.'-->CURRENCY:'.$r->base_currency.' - PAID CANCELLATION FEE', PHP_EOL;
				}
				else if (!$isActive) {
					echo ++$i.'-'.$r->user_id.' -> '.$r->subscription_type_id.'-->TOTAL AMOUNT DUE: '.$r->total_amount_due.'-->CURRENCY:'.$r->base_currency.' - ALREADY UNSUBSCRIBED', PHP_EOL;
				}
				else if ($hasOverdue) {
					echo ++$i.'-'.$r->user_id.' -> '.$r->subscription_type_id.'-->TOTAL AMOUNT DUE: '.$r->total_amount_due.'-->CURRENCY:'.$r->base_currency.' - ALREADY IN OVERDUE ACCOUNTS', PHP_EOL;
				}
				$this->updateSubscriptionFestivalCharges($r->id, $r->split_1_current_state, 3);
			}			
		}
	}
	function recentPayment21Days($userId) {
		$s = "SELECT * FROM pay_history WHERE user_id = '".$userId."' AND action = '".self::ACTION_SUCCESS."' AND timestamp >= '".strtotime("-21 days", time())."'";
		$q = $this->db_query($s);
		
		if ($q) {
			if (mysql_num_rows($q) > 0) {
				return true;
			}
		}
		
		return FALSE;
	}
	protected function addHistory($userId, $action, $msg, $cardId, $cctId, $amount, $currency, $noOfMonths=1) {
		if ($amount > 1) {
			$remarks = sprintf("%s month/s membership fee.", $noOfMonths);
		}
		else {
			$remarks = "Transaction fee.";
		}
		$s = "INSERT INTO pay_history (user_id, timestamp, readable, action, msg, card_id, credit_card_transaction_id, amount, currency, remarks) VALUES ('".$userId."', '".time()."', '".date("Y-m-d H:i:s", time())."', '".$action."', '".addslashes($msg)."', '".$cardId."', '".$cctId."','".$amount."','".$currency."','".$remarks."')";
		$this->db_query($s);
		
		/* Return pay_history.id*/
//		return mysql_insert_id();
		
	}
	
	protected function getTransactionObject($paymentGateway) {
		if ($paymentGateway == self::SECURE_3D || $paymentGateway == self::SECURE_3D2) {
			return new wirecard_maestro();
		} else {
			return new wirecard();
		}
	}
	
	/**
	 * Fetches the oldest known GuWID from this credit card which has been successful
	 *
	 * @param int $creditCardId
	 * @return int
	 */
	protected function getCurrentGUWID($creditCardId) {
		$s = "SELECT * FROM credit_card_transactions WHERE credit_card_id = '" . $creditCardId . "' AND (authcode IS NOT NULL AND authcode != '' AND authcode != '000000') ORDER BY id ASC LIMIT 0,1";
		$q = $this->db_query($s);
		$r = mysql_fetch_object($q);
		
		return $r->guwid;
	}
	
	protected function convertCheck($creditCardId, $userId) {
/*		$s = "SELECT * FROM credit_card_transactions WHERE credit_card_id = '" . $creditCardId . "' AND (authcode IS NOT NULL AND authcode != '' AND authcode != '000000') AND created < '2010-06-25 00:00:00' ORDER BY id ASC LIMIT 0,1";
		$q = $this->db_query($s);
*/		
		$s = "SELECT * FROM pay_history WHERE action = '".self::ACTION_CONVERT ."' AND user_id = '".$userId."'";
		$q1 = $this->db_query($s);
		
		if (mysql_num_rows($q1) > 0) {
			
			return TRUE;
		}
		
/*		if (mysql_num_rows($q) > 0) {
			return TRUE;
		}
*/		
		return FALSE;
	}
	
	function doTransaction($cDetails = array(), $split = "") {
		global $old, $new, $config;
		//echo print_r($cDetails);
		$s = "SELECT * FROM charge_plates WHERE user_id = '".$cDetails->user_id."' AND success = '1' AND banned = '0' ORDER BY id DESC LIMIT 0,1";
		//echo $s, PHP_EOL;
		
		$q = $this->db_query($s);

		/* Check if user has a credit card */
		if (!$q || mysql_num_rows($q) == 0) {
			//print 'NO SUCCESSFUL CREDIT CARD FOR THIS USER! MUST BE GC CUSTOMER THEN :-D<br>';
//			$this->disablePlus($userId);
			$this->addToOverdueAccounts($cDetails->user_id,0);
			echo $cDetails->user_id.' -> ADDED TO OVERDUE ACCOUNT. NO SUCCESSFUL CARD, MUST BE GC CUSTOMER', PHP_EOL;
			
			$this->updateSubscriptionFestivalCharges($cDetails->id, $split, 3);
			echo $cDetails->user_id.' -> CLOSING FESTIVAL RECORD', PHP_EOL;
			return;
		}
		
		$r = mysql_fetch_object($q);
		//echo print_r($r);
		//$noOfMonths = $this->getNumberOfMonths($cDetails->user_id);
		$amount = 0;
		if ($split == 1) {
			/*
			 * if ($chargeable_months > 1 and $chargeable_months <= $total_months_due) {$chargeable_months -= 1; } else { $chargeable_months = $total_months_due; }
			 * 
			 * 
			 */
			
			if ($cDetails->chargeable_months > 1 && $cDetails->chargeable_months <= $cDetails->total_months_due && $cDetails->chargeable_months <= 3) {
				$chargeable_months = $cDetails->chargeable_months - 1;
			}
			else {
				$chargeable_months = $cDetails->total_months_due;
			} 
			 
			//$amount = $cDetails->total_amount_due - 1;
			$amount = ($chargeable_months * $cDetails->base_amount) - 1;

			// if ($this->userHasCancellationRequest($cDetails->user_id) && $cDetails->total_months_due >= $cDetails->chargeable_months) {
				// $amount = (($cDetails->chargeable_months - 1) * $cDetails->base_amount) - 1;
			// }

		}
		else if ($split == 2) {
			$amount = 1;
		}
		else {
			if ($cDetails->chargeable_months >= 1 && $cDetails->chargeable_months <= $cDetails->total_months_due && $cDetails->chargeable_months <= 3) {
				$amount = $cDetails->chargeable_months * $cDetails->base_amount;
			}
		}
			
		// 
		$transaction = $this->getTransactionObject($r->payment_gateway);
		
		$transaction->_set_var("jobid", "");
		$transaction->_set_var("functionid", "");
		$transaction->_set_var("transactionid", "");
		
		$transaction->_set_var("amount", $amount*100);
		$transaction->_set_var("currency", $cDetails->base_currency);
		$transaction->_set_var("countrycode", "MA");
		$transaction->_set_var("usage", "Ziinga.com");
		
		// Get users IP-address
		$s = "SELECT ip FROM users WHERE id = '".$cDetails->user_id."'";
		$qUser = $this->db_query($s);
		$rUser = mysql_fetch_object($qUser);
		
		$transaction->_set_var("ipaddress", $rUser->ip);

		$transaction->_set_var("guwid", $r->transaction_guwid);
		$transaction->_set_var("recurring_transaction_type", "Repeated");
		$return_xml	= $transaction->request_cc_transaction_recurring();
		
		$updateGuwid = FALSE;
		
		$parsed_xml	= $transaction->parse_xml($return_xml);
		$result		= $transaction->cc_transaction_result($parsed_xml[1]);
		
		//print_r($result);
		
		$s = "INSERT INTO credit_card_transactions (credit_card_id, currency, guwid, authcode, message, advice, amount, created, modified) VALUES ('".$r->id."', '".$cDetails->base_currency."', '".$result["GuWID"]."', '".$result["AuthorizationCode"]."', '".str_replace("'", "", $result["Message"])."', '".str_replace("'", "", $result["Advice"])."', '".$amount."', '".date("Y-m-d H:i:s", time())."', '".date("Y-m-d H:i:s", time())."')";
		$this->db_query($s);
		
		$cctId = mysql_insert_id();
		
		/* ERROR */
		if ($result["FunctionResult"] != "ACK" && $result["FunctionResult"] != "PENDING") {
	
			if ($result["Message"]) {
				
				$err = $result["Message"];
				if ($result["Advice"]) {
					$err .= "\n".$result["Advice"];
				}

				if ($result["Message"] == self::NO_PROCESSING) {
					//print 'Wirecard has now temporary caused problems with collection. Wait for half an hour.';
					//break;
					//exit;
				}
				
			}
			$state = 0;
			$this->addHistory($cDetails->user_id, self::ACTION_FAIL, self::ACTION_FAIL_MSG.': '.$err, $r->id, $cctId, $amount, $this->getCurrency());
			echo '***MESSAGE: '.self::ACTION_FAIL_MSG.':'.$err.'::'.$cDetails->user_id, PHP_EOL;
			
			$this->statsArray['ERRORS']++;

			if (stristr($err, self::WRONG_REFERENCE)) {
				
				if (!empty($r->payment_gateway)) {
					$query = "UPDATE charge_plates SET payment_gateway = NULL WHERE id = '".$r->id."'";
					$this->db_query($query);
				}
				echo '***UPDATE PAYMENT_GATEWAY FOR USERID '.$cDetails->user_id.'***', PHP_EOL;
				//return FALSE;
				
				$this->addHistory($cDetails->user_id, self::ACTION_CONVERT, self::ACTION_CONVERT_MSG, $r->id, $cctId, $amount, $this->getCurrency());
				//print self::ACTION_CONVERT_MSG;
				return FALSE;
			}
			
			/* Disable cards */
			if ($result["Message"] == self::RETAIN_CARD || $result["Message"] == self::EXPIRED_CARD || $result["Message"] == self::INVALID_CARD || $result["Message"] == self::LOST_CARD || $result["Message"] == self::STOLEN_CARD || strstr($result["Message"], self::INVALID_TRANSACTION) || strstr($result["Message"], self::CVC_INVALID) || strstr($result["Message"], self::EXPIRATION_DATE_INVALID) || strstr($result["Message"], self::RESTRICTED_CARD)) {
				$this->disableCreditCard($r->id);
				$this->disablePlus($cDetails->user_id);
				$this->addHistory($cDetails->user_id, self::ACTION_DISABLE, self::ACTION_DISABLE_MSG, $r->id, $cctId, $amount, $this->getCurrency());
				$state = 2;
				//Add this user to overdue_accounts table
				$this->addToOverdueAccounts($cDetails->user_id,$cctId);
			}
			else {
				if ($cDetails->chargeable_months > 1 && $cDetails->total_months_due >= $cDetails->chargeable_months) {
					$this->updateChargeableMonths($cDetails->id);
				}
				else {				
					/* Handle next billing for this user */
					switch ($r->error_state) {
						
						case 0:
		
							$this->setErrorStateOnCreditCard(1, $r->id);
							$this->setErrorsOnCreditCard(($r->errors + 1), $r->id);
							$this->setNextBillingDate(strtotime("+" . self::ERROR_STATE_1_DAYS . " days"), $cDetails->user_id);
							break;
							
						case 1:
							
							if ($r->errors >= 5) {
								$this->setErrorStateOnCreditCard(2, $r->id);
								$this->setErrorsOnCreditCard(0, $r->id);
								$nextBillingDate = strtotime("-3 days", mktime(0, 0, 0, date("m")+1, 1, date("Y")));
								
								if ($nextBillingDate <= time()) {
									$nextBillingDate = strtotime("+1 month", $nextBillingDate);
								}
								
								$this->setNextBillingDate($nextBillingDate, $cDetails->user_id);
							} else {
								$this->setErrorsOnCreditCard(($r->errors + 1), $r->id);
								$this->setNextBillingDate(strtotime("+" . self::ERROR_STATE_1_DAYS . " days"), $cDetails->user_id);
							}
							break;
							
						case 2:
		
							if ($r->errors >= 5) {
								$this->disableCreditCard($r->id);
								$this->disablePlus($cDetails->user_id);
								$this->addToOverdueAccounts($cDetails->user_id,$cctId);
								$this->addHistory($cDetails->user_id, self::ACTION_DISABLE, self::ACTION_DISABLE_MSG, $r->id, $cctId, $amount, $this->getCurrency());
						
								//print self::ACTION_DISABLE_MSG.'::'.$r->user_id.'<br>';
							} else {
								$this->setErrorsOnCreditCard(($r->errors + 1), $r->id);
								$this->setNextBillingDate(strtotime("+" . self::ERROR_STATE_2_DAYS . " days"), $cDetails->user_id);
							}
							break;
					}
					
				}	
			}
			
			$this->updateSubscriptionFestivalCharges($cDetails->id, $split, $state);
			return FALSE;
		}
	
		/* SUCCESS */
		if ($result["FunctionResult"] == "ACK" || $result["FunctionResult"] == "PENDING") {
			
			/* Set guwid for next recurring */
			
			

			$supportDescription = sprintf("Membership charge for %s months worth %s %s", $chargeable_months, $amount, $cDetails->base_currency);
			if ($amount == 1) {
				$supportDescription = sprintf("Membership transaction fee worth %s %s", $amount, $cDetails->base_currency);
			}
			$diffDays = 0;
			$lastPayDate = $this->getLastSuccessfulCharge($cDetails->user_id);
			if (!empty($lastPayDate)) {
				$diffDays=(time() - strtotime($lastPayDate->readable))/ (60 * 60 * 24);
			}			
			if ($split == 1) {
				$this->setNextBillingDate(strtotime("+".(30 * $chargeable_months) + $diffDays." days"), $cDetails->user_id);
			}			
//			$payHistoryId = $this->addHistory($r->user_id, self::ACTION_SUCCESS, self::ACTION_SUCCESS_MSG, $r->id, $cctId, $this->getAmount(), $this->getCurrency());
			$this->addHistory($cDetails->user_id, self::ACTION_SUCCESS, self::ACTION_SUCCESS_MSG, $r->id, $cctId, $amount, $cDetails->base_currency, $chargeable_months);
			$this->addSupportHistory($cDetails->user_id, $supportDescription);
			$this->statsArray['SUCCESS']++;
			$this->statsArray[$this->getCountryId()]['AMOUNT'] += $amount;
			$this->updateSubscriptionFestivalCharges($cDetails->id, $split, 1);			
			echo '*****MESSAGE: '.self::ACTION_SUCCESS_MSG.'::'.$cDetails->user_id, PHP_EOL;

			//------------------>Update subscription_festival_charges, flag necessary record

			return TRUE;
		}
	
	}
	
	public function getLastSuccessfulCharge($userId) {
		$s = "SELECT * FROM pay_history WHERE user_id='$userId' AND amount > 1 AND DATE(readable) < '2012-10-17' ORDER BY id DESC LIMIT 1" ;
		//echo $s, PHP_EOL;
		$r = $this->db_query($s);
		
		$row = mysql_fetch_object($r);
		
		return $row;
	}
	
	public function setErrorStateOnCreditCard($state, $cardId) {
		$s = "UPDATE charge_plates SET error_state = '".$state."', modified = '".date("Y-m-d H:i:s")."' WHERE id = '".$cardId."'";
		$this->db_query($s);
	}
	
	public function setErrorsOnCreditCard($errors, $cardId) {
		$s = "UPDATE charge_plates SET errors = '".$errors."', modified = '".date("Y-m-d H:i:s")."' WHERE id = '".$cardId."'";
		$this->db_query($s);
	}

	public function disableCreditCard($cardId) {
		$s = "UPDATE charge_plates SET banned = '1', modified = '".date("Y-m-d H:i:s")."' WHERE id = '".$cardId."'";
		$this->db_query($s);
	}
	
	protected function setNextBillingDate($time, $userId) {
		$nextBillingDate = date("Y-m-d", $time);
		$s = "UPDATE users_pluses SET next_billing_date = '".$nextBillingDate."', modified = '".date("Y-m-d H:i:s")."' WHERE user_id = '".$userId."'";
		$this->db_query($s);
	}
	
	protected function disablePlus($userId) {
		$this->statsArray['DISABLE_PLUS']++;
		/**
		 * Date: 2011-10-21
		 * Updated By: Melvin Aquino
		 * Instead of unsubscribing the user directly, it's better to insert a subscription change request so we can track the membership's history
		 */
		//$s = "UPDATE users_pluses SET modified = '".date("Y-m-d H:i:s")."', unsubscribe_date = '".date("Y-m-d")."', status = 0 WHERE user_id = '".$userId."'";
		$upsId = $this->getUserMembership($userId);
		
		$s = "INSERT INTO subscription_change_requests (status, action, created, created_by, action_date, users_pluses_id, next_billing_date) VALUES (0, 'schedule', NOW(), 1576015, NOW(), '{$upsId}', '0000-00-00')";
		$this->db_query($s);
	}
	
	/**
	 * This method gets the membership id from users_pluses table
	 * @param $userId
	 * @return users_pluses.id
	 */
	private function getUserMembership($userId) {
		$s = "SELECT id FROM users_pluses WHERE user_id={$userId}";
		$r = $this->db_query($s);
		
		$ups = mysql_fetch_object($r);
		
		return $ups->id;
	}
	
	/**
	 * @description Add a new record to overdue_accounts table once the user get unsubscribed by the recurring script
	 * @author Melvin Aquino
	 * @date 2012-04-30
	 * @param $userId
	 * @param $cctId
	 * @return true
	 */
	function addToOverdueAccounts($userId, $cctId) {
		$s = "INSERT INTO overdue_accounts (user_id, credit_card_transaction_id, created) VALUES ('{$userId}', 0, NOW())";
		$this->db_query($s);
		
		return true;
	}
	function addToReceiptQueue($userId) {
		$s = "INSERT INTO recurring_receipt_queue (user_id, email_sent, created) VALUES ({$userId},0,NOW())";
		$this->db_query($s);
		
		return true;
	}
	/**
	 * @description Get user account details
	 * @date 2012-05-04
	 * @param $userId
	 * @return array() - user info
	 */
	function getUserInfo($userId) 
	{
		$s = "SELECT users.*, countries.code FROM users JOIN countries ON users.country_id=countries.id WHERE users.id={$userId}";
		$r = $this->db_query($s);
		
		$result = mysql_fetch_object($r);
		
		return $result;
	}
	
	function insertAttempt($id, $split) {
		$splitField = "split_".$split."_total_attempts";
		$attemptField = "split_".$split."_last_attempted_date";
		$s = "UPDATE subscription_festival_charges SET total_attempts = total_attempts + 1, $splitField = $splitField + 1, $attemptField = NOW(), last_attempted_date=NOW() WHERE id=$id";
		$this->db_query($s);
	}

	function updateSubscriptionFestivalCharges($id, $split, $updateValue) {
		if (in_array($updateValue, array(2,3))) {
			$s = "UPDATE subscription_festival_charges SET split_1_current_state = $updateValue WHERE id = $id AND split_1_current_state = 0";
			$this->db_query($s);
			
			$s = "UPDATE subscription_festival_charges SET split_2_current_state = $updateValue WHERE id = $id AND split_2_current_state = 0";
			$this->db_query($s);
		} else {
			$splitField = "split_".$split."_current_state";
			$s = "UPDATE subscription_festival_charges SET $splitField = $updateValue WHERE id=$id";
			$this->db_query($s);
		}
		
	}
	
	function addSupportHistory($userId, $action) {
		$s = "INSERT INTO support_actions_history (user_id, support_id, action, created, modified) VALUES ('$userId', 24, '$action', NOW(), NOW())";
		$this->db_query($s);
	}
	
	function userPaidCancellation($userId) {
		$s = "SELECT COUNT(id) AS paidCancellation FROM accounts WHERE user_id='$user_id' AND account_type_id=8";
		$r = $this->db_query($s);
		
		$row = mysql_fetch_object($r);
		if ($row->paidCancellation > 0) {
			return true;
		}
		
		return false;
	}
	
	function userHasCancellationRequest($userId) {
		$membershipId = $this->getUserMembership($userId);
		
		$s = "SELECT scr.* FROM subscription_change_requests AS scr WHERE users_pluses_id = '$membershipId' AND action_status = 0 AND status = 0 AND action = 'schedule' AND action_date >= (SELECT modified FROM users_pluses WHERE id = scr.users_pluses_id) AND action_date <= NOW() LIMIT 1";
		$r = $this->db_query($s);
		
		$row = mysql_fetch_object($r);
		
		if (!empty($row)) {
			return true;
		}
		return false;
	}
	
	function updateChargeableMonths($id) {
		$s = "UPDATE subscription_festival_charges SET chargeable_months = chargeable_months - 1 WHERE id = '$id'";
		$this->db_query($s);
	}
	
	function isActive($userId) {
		$s = "SELECT status, billable FROM users_pluses WHERE user_id={$userId}";
		$r = $this->db_query($s);
		
		$ups = mysql_fetch_object($r);
		
		if ($ups->status == 0 || $ups->billable == 0) {
			return false;
		}
		return true;
	}
	
	function hasOverdue($userId) {
		$s = "SELECT * FROM overdue_accounts WHERE user_id={$userId} AND credit_card_transaction_id = 0 AND status = 0";
		$r = $this->db_query($s);
		
		$ups = mysql_fetch_object($r);
		
		if (empty($ups)) {
			return false;
		}
		return true;
	}
}
?>