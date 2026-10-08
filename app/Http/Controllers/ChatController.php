<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Messagerie entre le Super Admin et les ADMIN de boutique. Une conversation
 * par admin ; tous les super admins partagent le même fil. Un admin n'accède
 * jamais qu'à SA conversation.
 */
class ChatController extends Controller
{
    use ApiResponse;

    /** SUPER_ADMIN : toutes les conversations. ADMIN : la sienne (créée à la demande). */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            ChatConversation::firstOrCreate(['admin_id' => $user->id]);
        }

        $q = ChatConversation::with(['admin.boutique', 'lastMessage'])->orderByDesc('updated_at');
        if ($user->isAdmin()) $q->where('admin_id', $user->id);

        return $this->success($q->get()->map(fn(ChatConversation $c) => $this->conversationPayload($c, $user))->values());
    }

    /** SUPER_ADMIN : ouvre (ou récupère) la conversation avec un admin. */
    public function open(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isSuperAdmin()) {
            throw new DomainException('Accès refusé.', 403, 'FORBIDDEN');
        }

        $data = $request->validate(['adminId' => 'required|uuid']);

        $admin = User::where('role', 'ADMIN')->find($data['adminId']);
        if (!$admin) throw new NotFoundException('Admin introuvable', 'USER_NOT_FOUND');

        $conversation = ChatConversation::firstOrCreate(['admin_id' => $admin->id]);
        $conversation->load(['admin.boutique', 'lastMessage']);

        return $this->success($this->conversationPayload($conversation, $user), 201);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findAccessible($request, $id);

        $messages = $conversation->messages()->with('sender')->orderBy('created_at')->orderBy('id')->get();

        return $this->success($messages->map(fn(ChatMessage $m) => $this->messagePayload($m))->values());
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findAccessible($request, $id);

        $data = $request->validate(['body' => 'required|string|max:2000']);
        $body = trim($data['body']);
        if ($body === '') {
            throw new DomainException('Message vide.', 422, 'VALIDATION_ERROR');
        }

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body'      => $body,
        ]);
        $conversation->touch();
        $message->load('sender');

        return $this->success($this->messagePayload($message), 201);
    }

    /** Marque comme lus les messages envoyés par l'autre partie. */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findAccessible($request, $id);

        $conversation->messages()
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->success(null);
    }

    private function findAccessible(Request $request, string $id): ChatConversation
    {
        $user = $request->user();
        $conversation = ChatConversation::find($id);

        // 404 (et non 403) pour ne pas révéler l'existence de la conversation d'un autre admin.
        if (!$conversation || ($user->isAdmin() && $conversation->admin_id !== $user->id)) {
            throw new NotFoundException('Conversation introuvable', 'CONVERSATION_NOT_FOUND');
        }

        return $conversation;
    }

    private function conversationPayload(ChatConversation $c, User $viewer): array
    {
        $admin = $c->admin;

        return [
            'id'          => $c->id,
            'admin'       => [
                'id'        => $admin->id,
                'email'     => $admin->email,
                'telephone' => $admin->telephone,
                'boutique'  => $admin->boutique ? ['id' => $admin->boutique->id, 'nom' => $admin->boutique->nom] : null,
            ],
            'lastMessage' => $c->lastMessage ? $this->messagePayload($c->lastMessage) : null,
            'unreadCount' => $c->messages()
                ->where('sender_id', '!=', $viewer->id)
                ->whereNull('read_at')
                ->count(),
            'updatedAt'   => $c->updated_at?->toISOString(),
        ];
    }

    private function messagePayload(ChatMessage $m): array
    {
        $sender = $m->relationLoaded('sender') ? $m->sender : $m->sender()->first();

        return [
            'id'             => $m->id,
            'conversationId' => $m->conversation_id,
            'senderId'       => $m->sender_id,
            'senderRole'     => $sender?->role === 'SUPER_ADMIN' ? 'SUPER_ADMIN' : 'ADMIN',
            'body'           => $m->body,
            'createdAt'      => $m->created_at?->toISOString(),
            'readAt'         => $m->read_at?->toISOString(),
        ];
    }
}
