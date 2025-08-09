<?php
namespace App\Services\LLM;

interface Provider {
    /**
     * @param array $messages  es. [['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
     * @param array $opts      es. ['model'=>'openai:gpt-5','max_tokens'=>1000,'temperature'=>0.7]
     * @return array           ['text'=>string,'usage'=>['input'=>int,'output'=>int,'total'=>int],'model'=>string]
     */
    public function chat(array $messages, array $opts = []): array;
}
