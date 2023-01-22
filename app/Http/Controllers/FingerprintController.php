<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Rats\Zkteco\Lib\ZKTeco;
use App\Models\Student;
use App\Models\StudentParent;
use App\Models\Sms;
use App\Models\SmsConfig;
use DB;
use Carbon\Carbon;
use App\Http\Controllers\ExamReportsController;

class FingerprintController extends Controller
{

	/* docs
		//    how to set user

		//    1 s't parameter int $uid Unique ID (max 65535)
		//    2 nd parameter int|string $userid ID in DB (same like $uid, max length = 9, only numbers - depends device setting)
		//    3 rd parameter string $name (max length = 24)
		//    4 th parameter int|string $password (max length = 8, only numbers - depends device setting)
		//    5 th parameter int $role Default Util::LEVEL_USER
		//    6 th parameter int $cardno Default 0 (max length = 10, only numbers

		//    return bool|mixed

    	//$zk->setUser();

		//    get attendance log

		//    return array[]

		//    like as 0 => array:5 [▼
		//              "uid" => 1      // serial number of the attendance 
		//              "id" => "1"     // user id of the application 
		//              "state" => 1    // the authentication type, 1 for Fingerprint, 4 for RF Card etc 
		//              "timestamp" => "2020-05-27 21:21:06" // time of attendance 
		//              "type" => 255   // attendance type, like check-in 0, check-out 1, overtime-in 4, overtime-out 5, break-in & break-out etc. if attendance type is none of them, it gives  255. 
		//              ]

		    //$zk->getAttendance(); 
	*/

// create viewing interface show last update; Also show can send sms

	public function index()
	{
		# return view for viewing the reports
	}
	public function uploadRecords(Request $request)
    {   
        // increase time and memory
        ini_set("memory_limit", "-1");
        ini_set("max_execution_time", "-1");
        
        $ipAddresses = $request->ip;
        $attendance = json_decode($request->data, true);

        $device_found = DB::table('zkteco_devices')->where('ip',$ipAddresses)->first();
 
        if ($device_found) {
        	$device_can_send=$device_found->can_send_sms ?? 0;
	        // "uid" => 40 "id" => "1" "state" => 1 "timestamp" => "2022-09-30 14:51:56" "type" => 0
	        $maxId = DB::table('zkteco_in_out_logs')->orderBy('id','Desc')->value('id') ?? 0;

			$filtered_attendance = array_filter($attendance, fn ($n) => $n['uid'] > $maxId);//get those not recorded
			
			$filtered_attendance = array_values($filtered_attendance);
			// store in db and create sms(if student and type is 0,1)
			for ($i=0; $i < count($filtered_attendance); $i++) { 
				$check=DB::table('zkteco_in_out_logs')->where('id',$filtered_attendance[$i]['uid'])->count();
				if ($check<1) {
					$smsSentEarlier=DB::table('zkteco_in_out_logs')->where('admission_number',$filtered_attendance[$i]['id'])->where('type',$filtered_attendance[$i]['type'])->whereDate('timestamp',Carbon::parse($filtered_attendance[$i]['timestamp'])->format('Y-m-d'))->count();

					$record_id=DB::table('zkteco_in_out_logs')->insertGetId([
						'id'=>$filtered_attendance[$i]['uid'],
						'admission_number'=>$filtered_attendance[$i]['id'],
						'state'=>$filtered_attendance[$i]['state'],
						'timestamp'=>$filtered_attendance[$i]['timestamp'],
						'type'=>$filtered_attendance[$i]['type']
					]);
					// if send sms is on
					if ($device_can_send=='1' && $smsSentEarlier<1 && Carbon::parse($filtered_attendance[$i]['timestamp'])->format('Y-m-d') == date('Y-m-d')) {
						//check-in 0, check-out 1, overtime-in 4, overtime-out 5
						$send=0;
						if ($filtered_attendance[$i]['type']=='0') {
							$send=1;
							$status='checked in';
						}
						if ($filtered_attendance[$i]['type']=='1') {
							$send=1;
							$status='checked out';
						}

						if ($filtered_attendance[$i]['type']=='255') {
							$send=1;
							$status='checked in';
						}

						if ($send) {
							// create sms
			                $student_details=Student::where('admission_number',$filtered_attendance[$i]['id'])->first();
			                $studentId=$student_details->id;
			                $phone=$student_details->contact ?? null;
			                if (is_null($phone)) {
			                    $phone=StudentParent::where('student_id',$studentId)->whereNotNull('contact')->value('contact') ?? '';
			                }
			                
			                if (!is_null($phone)) {
				                $reports_controller = new ExamReportsController;
				                $phonenumber=$reports_controller->sanitizeNumber($phone);
				                
				                $save=SmsConfig::create([
				                        'status'=>'processing',
				                        'send_at'=>0,
				                        'created_at'=>now()
				                    ]);
				                $config_id=$save->id;
				                $message="Dear parent, your child ".$student_details->name.", adm no ".$student_details->admission_number.' has '.$status.' the school at '.$filtered_attendance[$i]['timestamp'].'. Thank you';
				                if (Sms::where('student_id',$studentId)->where('sms_config_id',$config_id)->where('message_to',$phonenumber)->where('message',$message)->count()<1 && !is_null($phonenumber)) {
				                    
				                    $data=[
				                        'student_id'=>$studentId,
				                        'sms_config_id'=>$config_id,
				                        'communication_type'=>'normal',
				                        'message_to'=>$phonenumber,
				                        'message'=>$message,
				                        'created_at'=>now()
				                    ];  
				                    $insert2=Sms::insert($data);
				                }
				                SmsConfig::where('id',$config_id)->update([
				                    'status'=>'processed'
				                    ]);
				                DB::table('zkteco_in_out_logs')->where('id',$record_id)->update([
				                    'sms_config_id'=>$config_id
				                ]);
			            	}
						}

					}//device can send
				}//end check
			}//end loop
        }
        		

    }

    public function addUser()
    {
    	/* get user array results
		   "uid" => 2
		   "userid" => "2"
		   "name" => "Student"
		   "role" => 0
		   "password" => ""
		   "cardno" => "0000000000 "
    	*/
		$students=Student::where('fingerprint_account_created',0)->get(['admission_number','id','name']);
        $zk = new ZKTeco(config('zkteco.ip'),config('zkteco.port'));
        if ($zk->connect()){        	
        	foreach ($students as $key=>$student) {
        		$role = 0; //14= super admin, 0=User :: according to ZKtecho Machine
	            $users = $zk->getUser();
	            $total = end($users);
	            $lastId=($total['uid'] ?? 0)+1;
	            $studentName=$student->name;
				$studentName=strlen($studentName) > 24 ? substr($studentName,0,24)."" : $studentName;
	            $accountCreated=$zk->setUser($lastId, $student->admission_number, $studentName, '1234', $role);
	            if ($accountCreated=='') {
	            	Student::where('id',$student->id)->update(['fingerprint_account_created'=>1]);
	            }
        	}
            return "Add user success";
        }
        else{
             return "Device not connected";
        }
    }


}
?>