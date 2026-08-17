<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\LookbookPhoto;
use App\Services\CloudinaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookbookPhotoController extends Controller
{
    use ApiResponse;

    public function __construct(private CloudinaryService $cloudinary) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo'     => 'required|string',
            'nom'       => 'sometimes|nullable|string|max:100',
            'telephone' => 'sometimes|nullable|string|max:30',
            'message'   => 'sometimes|nullable|string|max:500',
        ]);

        if (!str_starts_with($data['photo'], 'data:image/')) {
            throw new DomainException('Format de photo invalide', 422, 'INVALID_PHOTO_FORMAT');
        }

        try {
            $url = $this->cloudinary->uploadBase64($data['photo'], 'lookbook-clients');
        } catch (\RuntimeException $e) {
            throw new DomainException('IMAGE_UPLOAD_FAILED: ' . $e->getMessage(), 422, 'IMAGE_UPLOAD_FAILED');
        }

        $photo = LookbookPhoto::create([
            'url'       => $url,
            'nom'       => $data['nom'] ?? null,
            'telephone' => $data['telephone'] ?? null,
            'message'   => $data['message'] ?? null,
        ]);

        return $this->success($photo, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $q = LookbookPhoto::query();

        if ($request->filled('statut')) {
            $q->where('statut', $request->string('statut'));
        }

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 24)));
        $total = $q->count();
        $data  = $q->orderBy('created_at', 'desc')->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    public function updateStatut(Request $request, string $id): JsonResponse
    {
        $photo = LookbookPhoto::find($id);
        if (!$photo) throw new NotFoundException('Photo introuvable', 'LOOKBOOK_PHOTO_NOT_FOUND');

        $data = $request->validate([
            'statut' => 'required|string|in:nouveau,vu,traite',
        ]);

        $photo->update(['statut' => $data['statut']]);

        return $this->success($photo->fresh());
    }

    public function updatePubliee(Request $request, string $id): JsonResponse
    {
        $photo = LookbookPhoto::find($id);
        if (!$photo) throw new NotFoundException('Photo introuvable', 'LOOKBOOK_PHOTO_NOT_FOUND');

        $data = $request->validate(['publiee' => 'required|boolean']);
        $photo->update(['publiee' => $data['publiee']]);

        return $this->success($photo->fresh());
    }

    public function publicIndex(): JsonResponse
    {
        $data = LookbookPhoto::where('publiee', true)
            ->orderBy('created_at', 'desc')
            ->limit(12)
            ->get(['id', 'url', 'nom', 'created_at']);

        return $this->success($data);
    }

    public function destroy(string $id): JsonResponse
    {
        $photo = LookbookPhoto::find($id);
        if (!$photo) throw new NotFoundException('Photo introuvable', 'LOOKBOOK_PHOTO_NOT_FOUND');

        $this->cloudinary->deleteByUrl($photo->url);
        $photo->delete();

        return $this->success($photo);
    }
}
