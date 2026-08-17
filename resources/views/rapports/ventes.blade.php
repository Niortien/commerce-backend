<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport des ventes</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
  h1 { font-size: 18px; margin-bottom: 4px; }
  p.period { color: #555; margin-top: 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th { background: #1a202c; color: #fff; padding: 8px; text-align: left; }
  td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
  tr:nth-child(even) td { background: #f7fafc; }
  .total { font-weight: bold; }
</style>
</head>
<body>
  <h1>Rapport des ventes</h1>
  <p class="period">Période : {{ $dateDebut }} → {{ $dateFin }}</p>

  <table>
    <thead>
      <tr>
        <th>Référence</th>
        <th>Type</th>
        <th>Total</th>
        <th>Date</th>
        <th>Vendeur</th>
      </tr>
    </thead>
    <tbody>
      @php $grandTotal = '0.00'; @endphp
      @foreach($sorties as $s)
        @php $grandTotal = bcadd($grandTotal, (string) $s->total_montant, 2); @endphp
        <tr>
          <td>{{ $s->reference }}</td>
          <td>{{ $s->type }}</td>
          <td>{{ number_format((float)$s->total_montant, 2, ',', ' ') }} FCFA</td>
          <td>{{ $s->created_at->format('d/m/Y H:i') }}</td>
          <td>{{ $s->user->email ?? '—' }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2" class="total">TOTAL</td>
        <td class="total">{{ number_format((float)$grandTotal, 2, ',', ' ') }} FCFA</td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>
</body>
</html>
