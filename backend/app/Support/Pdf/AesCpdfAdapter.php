<?php

namespace App\Support\Pdf;

/**
 * Dompdf's CPDF canvas, writing through AesCpdf (AES-256) instead of Dompdf\Cpdf. Same set-up as the parent constructor;
 * only the writer's class differs. Chosen with the Dompdf option pdfBackend (PdfSeal::options()).
 */
class AesCpdfAdapter extends \Dompdf\Adapter\CPDF
{
    public function __construct($paper = 'letter', string $orientation = 'portrait', ?\Dompdf\Dompdf $dompdf = null)
    {
        parent::__construct($paper, $orientation, $dompdf);
        $this->_pdf = new AesCpdf([0, 0, $this->_width, $this->_height], true,
            $this->_dompdf->getOptions()->getFontCache(), $this->_dompdf->getOptions()->getTempDir());
        $this->_pdf->addInfo('Producer', sprintf('%s + CPDF', $this->_dompdf->version));
        $time = substr_replace(date('YmdHisO'), '\'', -2, 0) . '\'';
        $this->_pdf->addInfo('CreationDate', "D:$time");
        $this->_pdf->addInfo('ModDate', "D:$time");
        if ($this->_dompdf->getOptions()->isPdfAEnabled()) {
            $this->_pdf->enablePdfACompliance();
        }
        $this->_pages = [$this->_pdf->getFirstPageId()];
    }
}
