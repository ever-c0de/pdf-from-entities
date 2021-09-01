<?php

namespace Drupal\pdf_from_entities\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Render\RendererInterface;
use mikehaertl\wkhtmlto\Pdf;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class PdfFromEntitiesPdfService {

  /**
   * Date storage.
   *
   * @var \Drupal\Core\Datetime\DrupalDateTime
   */
  protected $date;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The renderer service.
   *
   * @var \Drupal\mikehaertl\wkhtmlto\Pdf;
   */
  protected $pdf;

  /**
   * Constructs a new State.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(RendererInterface $renderer) {
    $options = [
      'no-outline',
      'page-size' => 'A4',
      'disable-smart-shrinking',
      'encoding' => 'UTF-8',
      'user-style-sheet' => DRUPAL_ROOT . '/modules/custom/pdf_from_entities/style/css/style.css',
    ];
    $this->date = new DrupalDateTime();
    $this->date = $this->date->format('m.d.o - H:i');
    $this->pdf = new Pdf($options);
    $this->renderer = $renderer;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer')
    );
  }

  function generatePdf($node, $type) {
    $template = $this->generateTemplate($node);
    $pdf = $this->pdf->addPage($template);

    // Save the PDF
    if (!$pdf->send()) {
      $error = $pdf->getError();
    }

    return true;
  }

  public function generateTemplate($node) {
    $date = $this->date;

    $template = [
      '#theme' => 'pdf_from_entities_pdf',
      '#node' => \Drupal::entityTypeManager()->getViewBuilder('node')->view($node, 'full'),
      '#date' => $date
    ];
    $html = $this->renderer
      ->renderRoot($template)
      ->__toString();
    $result = new Response($html, 200);

    return $result->getContent();
  }

}
