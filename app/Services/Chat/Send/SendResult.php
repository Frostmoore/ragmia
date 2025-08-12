<?php
declare(strict_types=1);

namespace App\Services\Chat\Send;

final class SendResult
{
    public function __construct(
        public string $answer,
        public array  $usage,          // ['input'=>int,'output'=>int,'total'=>int,'model'=>string,'compress'=>?array,'cost'=>float]
        public array  $project,        // ['id'=>int,'path'=>string]
        public array  $debug           // ['planner_raw'=>string,'final_input'=>string,'need_source'=>bool]
    ) {}
}
