<?php
namespace App\Repositories;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageRetrieval
{
    public function retrieveForQuery(int $projectId, string $query, int $k=8, int $recN=3, int $limitTokens=1200): array
    {
        $cands = $this->hasEmbeddings() ? $this->semantic($projectId, $query) : $this->keyword($projectId, $query);
        usort($cands, fn($a,$b)=>$b['score']<=>$a['score']);
        $topK = array_slice($cands, 0, $k);

        $recents = Message::where('project_id',$projectId)->orderByDesc('id')->limit($recN)->get(['id','content']);
        foreach ($recents as $r) {
            $topK[] = ['id'=>$r->id,'type'=>'message','score'=>0,'content'=>$this->clip($r->content,900),'why'=>'recency'];
        }

        $seen=[]; $final=[]; $budget=$limitTokens*4; $used=0;
        foreach ($topK as $c) {
            if (isset($seen[$c['id']])) continue;
            $seen[$c['id']] = true;
            $len = mb_strlen($c['content']);
            if ($used + $len > $budget) break;
            $final[]=$c; $used += $len;
        }
        return ['chunks'=>$final];
    }

    private function hasEmbeddings(): bool
    {
        try { return in_array('message_embeddings', DB::connection()->getDoctrineSchemaManager()->listTableNames()); }
        catch (\Throwable) { return false; }
    }

    private function semantic(int $projectId, string $query): array
    {
        $rows = DB::table('message_embeddings')
            ->join('messages','messages.id','=','message_embeddings.message_id')
            ->where('messages.project_id',$projectId)
            ->select('messages.id','messages.content','message_embeddings.embedding')
            ->orderByDesc('messages.id')->limit(4000)->get();

        $qVec = $this->hashVec($query);
        $out=[];
        foreach ($rows as $r) {
            $sim = $this->cosine($qVec, (array)json_decode($r->embedding,true));
            $out[] = ['id'=>(int)$r->id,'type'=>'message','score'=>$sim,'content'=>$this->clip($r->content,1200),'why'=>'semantic'];
        }
        return $out;
    }

    private function keyword(int $projectId, string $query): array
    {
        $rows = Message::where('project_id',$projectId)->orderByDesc('id')->limit(400)->get(['id','content']);
        $terms = array_values(array_filter(preg_split('/\W+/', Str::lower($query))));
        $out=[];
        foreach ($rows as $r) {
            $t = Str::lower($r->content); $score=0;
            foreach ($terms as $w) { $score += substr_count($t,$w); }
            $out[] = ['id'=>$r->id,'type'=>'message','score'=>(float)$score,'content'=>$this->clip($r->content,1200),'why'=>'keyword'];
        }
        return $out;
    }

    private function clip(string $txt,int $max): string
    { $txt=trim($txt); return mb_strlen($txt) <= $max ? $txt : (mb_substr($txt,0,$max-10).' […]'); }

    private function hashVec(string $text): array
    {
        $hash = substr(hash('sha256',$text),0,128); $vec=[];
        for($i=0;$i<128;$i+=2){ $vec[] = hexdec(substr($hash,$i,2))/255; }
        return $vec;
    }
    private function cosine(array $a,array $b): float
    {
        $n=min(count($a),count($b)); if(!$n) return 0.0;
        $dot=$na=$nb=0; for($i=0;$i<$n;$i++){ $dot+=$a[$i]*$b[$i]; $na+=$a[$i]*$a[$i]; $nb+=$b[$i]*$b[$i]; }
        if(!$na||!$nb) return 0.0; return $dot/(sqrt($na)*sqrt($nb));
    }
}
