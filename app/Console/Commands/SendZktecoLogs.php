<?php

namespace App\Console\Commands;
use Rats\Zkteco\Lib\ZKTeco;
use Illuminate\Support\Facades\Http;

use Illuminate\Console\Command;

class SendZktecoLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zkteco:logs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch zkteco logs and post to server';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        /*
            To add authenticstion later
        */
        $ipAddresses = ['192.168.0.100'];
        //p'172.16.120.201','172.16.120.202','172.16.120.203','172.16.120.204'];
        $port = 4370;
        $url = 'https://kaagagirls.parpus.com/public/api/zkteco/post';

        ini_set("memory_limit", "-1");
        ini_set("max_execution_time", "-1");
                
        for ($i=0; $i < count($ipAddresses); $i++) {
            
            $current_ip=$ipAddresses[$i];

            // create an instance
            $zk = new ZKTeco($current_ip,$port);
            // connect
            if ($zk->connect()){
                $zk->enableDevice();

                /*
                        $zk->enableDevice(ZKFingerprint::FUNCTION_USERTEMP);

                        $startUserId = 0;
                        $maxUsers = 127;
                        $allUsers = [];

                        while (true) {
                            $users = $zk->getUser($startUserId, $maxUsers);

                            if (empty($users)) {
                                break;
                            }

                            $allUsers = array_merge($allUsers, $users);

                            $startUserId = end($users)['id'] + 1;
                        }

                        $zk->disconnect();

                        dd($allUsers);
                */

                $attendance = $zk->getAttendance();

                // post logs to server
                $response = Http::asForm()->post($url, [
                    'ip' => $current_ip,
                    'data' => json_encode($attendance)
                ]);

                \Log::info($response);

            }else{
                \Log::info($current_ip.' Failed');
                // echo 'Failed to connect'; //return
            }
        }
    
        return Command::SUCCESS;
    }
}
