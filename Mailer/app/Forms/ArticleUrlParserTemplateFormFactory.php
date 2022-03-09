<?php
declare(strict_types=1);

namespace Remp\Mailer\Forms;

use Nette\Application\UI\Form;
use Nette\Security\User;
use Remp\MailerModule\Models\Auth\PermissionManager;
use Remp\MailerModule\Repositories\BatchesRepository;
use Remp\MailerModule\Repositories\JobsRepository;
use Remp\MailerModule\Repositories\LayoutsRepository;
use Remp\MailerModule\Repositories\ListsRepository;
use Remp\MailerModule\Repositories\SourceTemplatesRepository;
use Remp\MailerModule\Repositories\TemplatesRepository;
use Remp\MailerModule\Models\Segment\Crm;

class ArticleUrlParserTemplateFormFactory
{
    private $segmentCode;

    private $layoutCode;

    private $templatesRepository;

    private $layoutsRepository;

    private $jobsRepository;

    private $batchesRepository;

    private $listsRepository;

    private $sourceTamplatesRepository;

    private $permissionManager;

    private $user;

    public $onUpdate;

    public $onSave;

    public function __construct(
        TemplatesRepository $templatesRepository,
        LayoutsRepository $layoutsRepository,
        ListsRepository $listsRepository,
        JobsRepository $jobsRepository,
        BatchesRepository $batchesRepository,
        SourceTemplatesRepository $sourceTemplatesRepository,
        PermissionManager $permissionManager,
        User $user
    ) {
        $this->templatesRepository = $templatesRepository;
        $this->layoutsRepository = $layoutsRepository;
        $this->listsRepository = $listsRepository;
        $this->jobsRepository = $jobsRepository;
        $this->batchesRepository = $batchesRepository;
        $this->sourceTamplatesRepository = $sourceTemplatesRepository;
        $this->permissionManager = $permissionManager;
        $this->user = $user;
    }

    public function setSegmentCode(string $segmentCode): void
    {
        $this->segmentCode = $segmentCode;
    }

    public function setLayoutCode(string $layoutCode): void
    {
        $this->layoutCode = $layoutCode;
    }

    public function create()
    {
        $form = new Form;
        $form->addProtection();

        if (!$this->segmentCode) {
            $form->addError("Default value 'segment code' is missing.");
        }

        if (!$this->layoutCode) {
            $form->addError("Default value 'layout code' is missing.");
        }

        $form->addText('name', 'Name')
            ->setRequired("Field 'Name' is required.");

        $form->addText('code', 'Identifier')
            ->setRequired("Field 'Identifier' is required.");

        $mailTypes = $this->listsRepository->getTable()
            ->where(['public_listing' => true])
            ->order('sorting ASC')
            ->fetchPairs('id', 'title');

        $form->addSelect('mail_type_id', 'Type', $mailTypes)
            ->setPrompt('Select type')
            ->setRequired("Field 'Type' is required.");

        $form->addText('from', 'Sender')
            ->setHtmlAttribute('placeholder', 'e.g. info@domain.com')
            ->setRequired("Field 'Sender' is required.");

        $form->addText('subject', 'Subject')
            ->setRequired("Field 'Subject' is required.");

        $form->addText('subject_b', 'Subject (B version)')
            ->setNullable();

        $form->addText('email_count', 'Batch size')
            ->setNullable();

        $form->addText('start_at', 'Start date')
            ->setNullable();

        $form->addHidden('mail_layout_id');
        $form->addHidden('source_template_id');
        $form->addHidden('html_content');
        $form->addHidden('text_content');

        $sourceTemplate = $this->sourceTamplatesRepository->find($_POST['source_template_id']);

        $defaults = [
            'source_template_id' => $sourceTemplate->id,
            'name' => "{$sourceTemplate->title} " . date('d. m. Y'),
            'code' => "{$sourceTemplate->code}_" . date('Y-m-d'),
        ];

        if ($this->layoutCode) {
            $defaults['mail_layout_id'] = (int)$this->layoutsRepository->findBy('code', $this->layoutCode)->id;
        }

        $form->setDefaults($defaults);

        if ($this->permissionManager->isAllowed($this->user, 'batch', 'start')) {
            $withJobs = $form->addSubmit('generate_emails_jobs', 'system.save');
            $withJobs->getControlPrototype()
                ->setName('button')
                ->setHtml('Generate newsletter batch and start sending');
        }

        $withJobsCreated = $form->addSubmit('generate_emails_jobs_created', 'system.save');
        $withJobsCreated->getControlPrototype()
            ->setName('button')
            ->setHtml('Generate newsletter batch');

        $form->onSuccess[] = [$this, 'formSucceeded'];
        return $form;
    }

    public function formSucceeded(Form $form, $values)
    {
        $generate = function ($htmlBody, $textBody, $mailLayoutId, $segmentCode = null) use ($values, $form) {
            $mailTemplate = $this->templatesRepository->add(
                $values['name'],
                $this->templatesRepository->getUniqueTemplateCode($values['code']),
                '',
                $values['from'],
                $values['subject'],
                $textBody,
                $htmlBody,
                (int)$mailLayoutId,
                $values['mail_type_id']
            );

            $jobContext = null;

            $mailJob = $this->jobsRepository->add($segmentCode, Crm::PROVIDER_ALIAS, $jobContext);
            $batch = $this->batchesRepository->add(
                $mailJob->id,
                (int)$values['email_count'],
                $values['start_at'],
                BatchesRepository::METHOD_RANDOM
            );
            $this->batchesRepository->addTemplate($batch, $mailTemplate);

            if (isset($values['subject_b'])) {
                $mailTemplateB = $this->templatesRepository->add(
                    $values['name'],
                    $this->templatesRepository->getUniqueTemplateCode($values['code']),
                    '',
                    $values['from'],
                    $values['subject_b'],
                    $textBody,
                    $htmlBody,
                    (int)$mailLayoutId,
                    $values['mail_type_id']
                );
                $this->batchesRepository->addTemplate($batch, $mailTemplateB);
            }

            $batchStatus = BatchesRepository::STATUS_READY_TO_PROCESS_AND_SEND;
            if ($form['generate_emails_jobs_created']->isSubmittedBy()) {
                $batchStatus = BatchesRepository::STATUS_CREATED;
            }

            $this->batchesRepository->updateStatus($batch, $batchStatus);
        };

        $generate(
            $values['html_content'],
            $values['text_content'],
            $values['mail_layout_id'],
            $this->segmentCode
        );

        $this->onSave->__invoke();
    }
}
