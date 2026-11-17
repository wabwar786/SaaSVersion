<?php
namespace Aio\Services;

/* ============================================================
   V197 — BILL KA TAKRAO (conflict).

   Do haalaat jin mein POS ka save ROKNA zaroori hai, warna paisa ya
   item ghalat ho jata hai:

   CLOSED  — bill pehle hi band (ya cancel) ho chuka hai. Dobara charge
             karne par pehle naya bill ban jata tha = do sale. Ab koi
             naya bill nahi banta; POS ko bata diya jata hai.
   CHANGED — bill is screen ke khulne ke baad kisi aur jagah (doosra
             counter / tablet) badla gaya. Purani screen ka save
             doosre ka kaam mita deta tha (hataya hua item wapas, ya
             tablet ka item bina paise). Ab POS bill taaza khol leta hai.
   ============================================================ */
final class PosConflict extends \RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly string $billNo = '',
        public readonly string $orderId = ''
    ) {
        parent::__construct($message);
    }
}

// build: V197 build 2026-11-17
