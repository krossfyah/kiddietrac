<?php

namespace App\Support;

/**
 * Dompdf, with the document translated into the reader's language first (2026-10-22,
 * "whole PDF"). Every PDF in the app is made with `new KtDompdf(...)` instead of
 * `new \Dompdf\Dompdf(...)`; nothing else about how a PDF is built changes.
 * The language is MessageTranslator::pdfLocale(): the viewer's for a download, or the one
 * set with MessageTranslator::withLocale() when a document is made to be sent.
 */
class KtDompdf extends \Dompdf\Dompdf
{
    public function loadHtml($str, $encoding = null)
    {
        $loc = MessageTranslator::pdfLocale();
        if ($loc && is_string($str) && $str !== '') {
            $str = MessageTranslator::html($str, $loc, 45);
        }

        return parent::loadHtml($str, $encoding);
    }
}
