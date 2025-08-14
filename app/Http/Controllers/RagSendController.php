<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Rag\RagContextService;

class RagSendController extends Controller
{
    public function __construct(private RagContextService $rag) {}

    public function send(Request $r)
    {
        $projectId = (int)$r->input('project_id');
        $prompt    = (string)$r->input('prompt','');
        $out = $this->rag->answer($projectId, $prompt, [
            'k' => (int)$r->input('k', 8),
            'rec_n' => (int)$r->input('rec_n', 3),
        ]);
        return response()->json($out);
    }
}
