<?php

namespace Drupal\pdf_from_entities\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PdfFromEntitiesForm extends ConfigFormBase {

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
    protected $entityTypeManager;


  /**
   * PdfFromEntitiesForm constructor.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   */
    public function __construct(EntityTypeManagerInterface $entityTypeManager) {
        $this->entityTypeManager = $entityTypeManager;
    }

  /**
   * {@inheritdoc}
   */
    public static function create(ContainerInterface $container) {
        return new static(
            $container->get('entity_type.manager')
        );
    }

  /**
   * Config name
   */
    const CONFIG_NAME = 'pdf_from_entities.settings';

  /**
   * {@inheritdoc}
   */
    protected function getEditableConfigNames(): array {
        return [self::CONFIG_NAME];
    }

  /**
   * {@inheritdoc}
   */
    public function getFormId(): string {
        return 'pdf_from_entities_module_settings_form';
    }

  /**
   * {@inheritdoc}
   */
    public function buildForm(array $form, FormStateInterface $form_state): array {
        $config = $this->config(self::CONFIG_NAME);



        return parent::buildForm($form, $form_state);
    }

  /**
   * {@inheritdoc}
   */
    public function validateForm(array &$form, FormStateInterface $form_state) {
        parent::validateForm($form, $form_state);
    }

  /**
   * {@inheritdoc}
   */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $config = $this->config(self::CONFIG_NAME);




        parent::submitForm($form, $form_state);
    }

  /**
   * Get notification user types.
   *
   * @return array
   *   User types.
   */
    public function getExistingEntities() :array {
        $this->entityTypeManager()->getDefinitions();
    }
}
