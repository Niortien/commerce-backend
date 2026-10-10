<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Http\Traits\ApiResponse;
use App\Models\Client;
use App\Models\OperationCredit;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clients à crédit (quincaillerie) : fiche, encours, retards, relevé de compte et règlements.
 * Le plafond de crédit est une décision de l'ADMIN.
 */
class ClientController extends Controller
{
    use ApiResponse;

    public function __construct(private CreditService $credits) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => 'sometimes|nullable|string|max:150',
            'filtre' => 'sometimes|nullable|in:AVEC_SOLDE,EN_RETARD',
            // « actifs » arrive en texte dans l'URL (actifs=true) : lu plus bas avec boolean(), sans règle stricte.
        ]);

        $q = Client::where('boutique_id', $this->tenantBoutiqueId($request))->orderBy('nom');
        if (!empty($data['search'])) {
            $s = $data['search'];
            $q->where(fn($w) => $w->where('nom', 'like', "%{$s}%")->orWhere('telephone', 'like', "%{$s}%"));
        }
        if ($request->boolean('actifs')) $q->where('is_actif', true);

        $clients = $q->get();
        $operations = OperationCredit::whereIn('client_id', $clients->pluck('id'))->orderBy('created_at')->orderByRaw(OperationCredit::ORDRE_A_HEURE_EGALE)->get()->groupBy('client_id');

        $resultat = $clients
            ->map(fn(Client $c) => array_merge($c->toArray(), $this->credits->etat($c, $operations->get($c->id, collect()))))
            ->filter(fn($c) => match ($data['filtre'] ?? null) {
                'AVEC_SOLDE' => bccomp($c['solde'], '0', 2) > 0,
                'EN_RETARD'  => bccomp($c['en_retard'], '0', 2) > 0,
                default      => true,
            })
            ->values();

        return $this->paginated($resultat, $resultat->count(), 1, max(1, $resultat->count()));
    }

    /** Fiche et relevé : chaque opération avec le solde après elle. */
    public function show(Request $request, string $id): JsonResponse
    {
        $client = $this->credits->clientDeLaBoutique($this->tenantBoutiqueId($request), $id);
        $operations = OperationCredit::with(['sortie:id,reference,total_montant', 'user:id,email'])
            ->where('client_id', $client->id)
            ->orderBy('created_at')->orderByRaw(OperationCredit::ORDRE_A_HEURE_EGALE)
            ->get();

        $solde = '0.00';
        $releve = $operations->map(function (OperationCredit $o) use (&$solde) {
            $solde = $o->type === 'VENTE'
                ? bcadd($solde, (string) $o->montant, 2)
                : bcsub($solde, (string) $o->montant, 2);
            return array_merge($o->toArray(), ['solde_apres' => $solde]);
        });

        return $this->success(array_merge(
            $client->toArray(),
            $this->credits->etat($client, $operations),
            ['operations' => $releve->reverse()->values()]
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->valider($request, true);
        $boutiqueId = $this->tenantBoutiqueId($request);
        $this->verifierNomLibre($boutiqueId, $data['nom']);

        $client = Client::create([
            'boutique_id'    => $boutiqueId,
            'nom'            => trim($data['nom']),
            'telephone'      => $data['telephone'] ?? null,
            // Un caissier peut créer le client ; seul l'ADMIN fixe sa limite de crédit.
            'plafond_credit' => $request->user()->role === 'ADMIN' ? ($data['plafondCredit'] ?? null) : null,
            'notes'          => $data['notes'] ?? null,
        ]);

        return $this->success(array_merge($client->fresh()->toArray(), $this->credits->etat($client)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $client = $this->credits->clientDeLaBoutique($boutiqueId, $id);
        $data = $this->valider($request, false);
        if (isset($data['nom']) && trim($data['nom']) !== $client->nom) $this->verifierNomLibre($boutiqueId, $data['nom']);

        $champs = ['nom' => 'nom', 'telephone' => 'telephone', 'plafondCredit' => 'plafond_credit', 'notes' => 'notes', 'isActif' => 'is_actif'];
        $changes = [];
        foreach ($champs as $cle => $colonne) {
            if (array_key_exists($cle, $data)) $changes[$colonne] = is_string($data[$cle]) ? trim($data[$cle]) : $data[$cle];
        }
        $client->update($changes);

        return $this->success(array_merge($client->fresh()->toArray(), $this->credits->etat($client)));
    }

    /** Le client paie : l'argent entre dans la caisse ouverte et sa dette baisse. */
    public function regler(Request $request, string $id): JsonResponse
    {
        $client = $this->credits->clientDeLaBoutique($this->tenantBoutiqueId($request), $id);
        $data = $request->validate([
            'montant'      => 'required|numeric|min:1',
            'modePaiement' => 'required|in:' . implode(',', CreditService::MODES_PAIEMENT),
            'notes'        => 'sometimes|nullable|string|max:255',
        ]);

        $operation = $this->credits->regler($client, $data['montant'], $data['modePaiement'], $request->user()->id, $data['notes'] ?? null);

        return $this->success(array_merge(['reglement' => $operation], $this->credits->etat($client)), 201);
    }

    /** Supprimer un client créé par erreur : seulement s'il n'a aucune opération. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $client = $this->credits->clientDeLaBoutique($this->tenantBoutiqueId($request), $id);
        if (OperationCredit::where('client_id', $client->id)->exists()) {
            throw new ConflictException('Ce client a un historique : désactivez-le plutôt', 'CLIENT_AVEC_HISTORIQUE');
        }
        $client->delete();

        return $this->success(['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function valider(Request $request, bool $creation): array
    {
        return $request->validate([
            'nom'           => ($creation ? 'required' : 'sometimes') . '|string|max:150',
            'telephone'     => 'sometimes|nullable|string|max:40',
            'plafondCredit' => 'sometimes|nullable|numeric|min:0',
            'notes'         => 'sometimes|nullable|string',
            'isActif'       => 'sometimes|boolean',
        ]);
    }

    private function verifierNomLibre(string $boutiqueId, string $nom): void
    {
        if (Client::where('boutique_id', $boutiqueId)->where('nom', trim($nom))->exists()) {
            throw new ConflictException("Un client « " . trim($nom) . " » existe déjà", 'CLIENT_EXISTE');
        }
    }
}
