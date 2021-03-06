<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Odd;
use App\UpcomingEvents;
use Carbon\Carbon;
use App\SyncKey;
use App\Notification;
use Symfony\Component\Process\Process;
use App\MarketsOddConverter;

class CheckOdds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:odds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check odds to find out is there something worthy';

    protected $filter = 2;

    protected $baseLink = 'https://betsapi.com/rs/bet365/';

    protected $oddsMarkets = [
        '18_2',
        '18_3',
        '18_5',
        '18_6',
        '18_8',
        '18_9',
    ];

    /**
     * Execute the console command.
     * @TODO Rewrite this! Make it more complex and easier   
     *
     * @return mixed
     */
    public function handle()
    {
        //$sync = SyncKey::all()->last();
        \Log::info('running check:odds - ' . Carbon::now());
        $events = UpcomingEvents::all();

        foreach ($events as $event) {

            $now = Carbon::now();
            $startTime = Carbon::parse(date('Y-m-d h:i:s', $event->time));
            $diffInHours = $startTime->diffInHours($now);

            if ($diffInHours > 12) continue;

            $this->info('Processing event: ' . $event->event_id);

            foreach ($this->oddsMarkets as $oddMarket) {

                $lastCheckedOdd = Odd::where('event_id', $event->event_id)
                    ->where('is_checked', 1)
                    ->where('odd_market', $oddMarket)
                    ->orderBy('id', 'DESC')
                    ->first();

                $notCheckedOdds = Odd::where('event_id', $event->event_id)
                    ->where('is_checked', 0)
                    ->where('odd_market', $oddMarket)
                    ->orderBy('id', 'ASC')
                    ->get();

                $isSentMessage = true;
                if (is_null($lastCheckedOdd)) $isSentMessage = false;

                foreach ($notCheckedOdds as $key => $odd) {

                    if ($odd->add_time >= $event->time) continue;

                    if (isset($notCheckedOdds[$key-1])) {
                        $handicap = (float) $odd->handicap - ((float) ($notCheckedOdds[$key-1]->handicap ?? 0));
                        $from = (float) ($notCheckedOdds[$key-1]->handicap ?? 0);
                    } else {
                        $handicap = (float) $odd->handicap - ((float) ($lastCheckedOdd->handicap ?? 0));
                        $from = (float) ($lastCheckedOdd->handicap ?? 0);
                    }

                    $sustainableDiffs = [];

                    if ($handicap > $this->filter) {
                        $sustainableDiffs['handicap'] = [
                            $handicap,
                            $odd->handicap,
                            $from,
                        ];
                    }

                    if (count($sustainableDiffs) > 0 && $isSentMessage) {
                        foreach ($sustainableDiffs as $key => $diff) {
                            $isNotificationsSent = Notification::where('odd_type', $oddMarket)
                                ->where('event_id', $event->event_id)
                                ->exists();

                                $color = 'GREEN';
                                if ($isNotificationsSent) $color = 'RED';
                                
                                $link = $this->baseLink 
                                    . $event->event_id 
                                    . '/' 
                                    . str_replace(' ', '-', $event->home_team_name)
                                    . '-v-'
                                    . str_replace(' ', '-', $event->away_team_name);

                                $marketOdd = MarketsOddConverter::convert($odd->odd_market);

                                $messageForDB = 
                                  '<i>' . $color . '</i>' . "\r\n"
                                . '<i>It seems, there is something worthy to check...</i>' . "\r\n" . '<b>' . $marketOdd . '</b> has been changed in <b>' . $diff[0] . '</b> points. Range: from ' . $diff[2] . ' to ' . $diff[1] . '. ' . $event->home_team_name . ' vs ' . $event->away_team_name . ' - ' . Carbon::createFromTimestampUTC($event->time) . '. (<a href="' . $link . '">Link to the event</a>)
                                ';

                                $notification = Notification::create([
                                    'event_id' => $event->event_id,
                                    'odd_id' => $odd->odd_id,
                                    'chat_ids' => '',
                                    'odd_type' => $oddMarket,
                                    'message' => $messageForDB,
                                    'is_done' => 0,
                                ]);

                                $this->info('Found sustainable changes. Sending notification to users.');
                                \Log::info('sending message - ' . Carbon::now());

                                $process = new Process('php artisan telegram:send ' . $notification->id); 
                                $process->start();
                        }
                    }

                    $odd->is_checked = 1;
                    $odd->save();
                    $this->info('Odd is checked');
                }
            }
        }

        \Log::info('check:odds if finished - ' . Carbon::now());
    }
}
