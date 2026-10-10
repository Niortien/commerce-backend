<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Le Super Admin peut ouvrir l'espace d'un admin ou d'un caissier pour voir ce qu'il voit.
 * Son jeton de consultation porte la revendication « lecture_seule » : toute écriture est refusée.
 */
class ConsultationLectureSeule
{
    private const METHODES_LECTURE = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): mixed
    {
        if (!in_array($request->method(), self::METHODES_LECTURE, true) && self::estConsultation()) {
            throw new DomainException(
                'Mode consultation : vous voyez cet espace sans pouvoir le modifier.',
                403,
                'CONSULTATION_LECTURE_SEULE'
            );
        }

        return $next($request);
    }

    /** Le jeton de la requête est-il un jeton de consultation du Super Admin ? */
    public static function estConsultation(): bool
    {
        try {
            return (bool) JWTAuth::parseToken()->getPayload()->get('lecture_seule');
        } catch (JWTException) {
            return false;
        }
    }
}
