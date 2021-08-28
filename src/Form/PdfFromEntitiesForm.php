<?php

namespace Drupal\pdf_from_entities\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
class PdfFromEntitiesForm extends ConfigFormBase {

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * PdfFromEntitiesForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   EntityTypeManager construct.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('entity_type.manager'),
      $container->get('messenger')
      );
  }

  /**
   * Config name.
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
    $entity_types = $this->getExistingEntities();

    $form['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => 'Choose entity-types which nodes will be converted to PDF',
    // '#default_value' => $config['entity_types'] ?? [],
      '#options' => $entity_types ?? [],
    ];

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

    $this->messenger()->addStatus($this->t('Configuration saved!'));
  }

  /**
   * Get notification user types.
   *
   * @return array
   *   User types.
   */
  public function getExistingEntities() :array {
    $entity_types = NULL;
    $node_type_storage = $this->entityTypeManager->getStorage('node_type')
      ->loadMultiple();

    if (isset($node_type_storage)) {
      foreach ($node_type_storage as $entity) {
        $entity_types[$entity->get('type')] = $entity->get('name');
      }
    } else{

    }
    $this->messenger
      ->addWarning($this->t("You don't have any created content-types. Please add at least one @add_entity.",[
        '@add_entity' => Link::createFromRoute('here','node.type_add')->toString(),
      ]));

    return $entity_types;
  }

}
