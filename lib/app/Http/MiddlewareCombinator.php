<?php

namespace App\Http;

final class MiddlewareCombinator
{
    private int $typeMask;

    public function __construct(int $typeMask)
    {
        $this->typeMask = $typeMask;
    }

    /**
     * @return array{before: array, main: MiddlewareDispatcher, after: array}
     */
    public function split(MiddlewareDispatcher $full): array
    {
        $before = [];
        $after  = [];
        $main   = new MiddlewareDispatcher();

        foreach ($full->all() as [$mw, $st]) {
            if (!(($st->typesMask ?? 0) & $this->typeMask)) {
                continue;
            }

            if ($st->stage === MiddlewareState::BEFORE) {
                $before[] = [$mw, $st];
            } elseif ($st->stage === MiddlewareState::AFTER) {
                $after[] = [$mw, $st];
            } else {
                $main->add($mw, $st);
            }
        }

        usort($before, fn($a,$b) => $a[1]->priority <=> $b[1]->priority);
        usort($after,  fn($a,$b) => $a[1]->priority <=> $b[1]->priority);

        return compact('before','main','after');
    }

    /**
     * Hooks:  middleware с (req,res,args) и (req,res,args,next), внимание есть разница между hook и middleware -> см $noopNext
     */
    public function runHooks(array $hooks, Request $req, Response $res, array $args): Response
    {
        $noopNext = function (Request $req, Response $res, array $args): Response {
            return $res;
        };

        foreach ($hooks as [$mw, $st]) {
            $out = $mw($req, $res, $args, $noopNext);

            if (!$out instanceof Response) {
                user_error('Hook middleware must return Response', E_USER_ERROR);
            }

            $res = $out;
        }

        return $res;
    }
}
