<?php
declare(strict_types=1);

namespace Remp\MailerModule\Presenters;

use Nette\Application\BadRequestException;
use Nette\Application\UI\Form;
use Remp\MailerModule\Repositories\ActiveRow;
use Nette\Utils\Json;
use Remp\MailerModule\Components\DataTable\DataTable;
use Remp\MailerModule\Components\DataTable\IDataTableFactory;
use Remp\MailerModule\Forms\SourceTemplateFormFactory;
use Remp\MailerModule\Repositories\SourceTemplatesRepository;

final class GeneratorPresenter extends BasePresenter
{
    private $sourceTemplatesRepository;

    private $sourceTemplateFormFactory;

    private $dataTableFactory;

    public function __construct(
        SourceTemplatesRepository $sourceTemplatesRepository,
        SourceTemplateFormFactory $sourceTemplateFormFactory,
        IDataTableFactory $dataTableFactory
    ) {
        parent::__construct();
        $this->sourceTemplatesRepository = $sourceTemplatesRepository;
        $this->sourceTemplateFormFactory = $sourceTemplateFormFactory;
        $this->dataTableFactory = $dataTableFactory;
    }

    public function createComponentDataTableDefault(): DataTable
    {
        $dataTable = $this->dataTableFactory->create();
        $dataTable
            ->setColSetting('created_at', [
                'header' => 'created at',
                'render' => 'date',
                'priority' => 2,
            ])
            ->setColSetting('title', [
                'priority' => 1,
            ])
            ->setColSetting('code', [
                'priority' => 1,
            ])
            ->setColSetting('generator', [
                'priority' => 1,
            ])
            ->setRowAction('edit', 'palette-Cyan zmdi-edit', 'Edit generator')
            ->setRowAction('generate', 'palette-Cyan zmdi-spellcheck', 'Generate emails')
            ->setTableSetting('sorting', Json::encode([[0, 'DESC']]));

        return $dataTable;
    }

    public function renderDefaultJsonData(): void
    {
        $request = $this->request->getParameters();

        $sourceTemplatesCount = $this->sourceTemplatesRepository
            ->tableFilter($request['search']['value'], $request['columns'][$request['order'][0]['column']]['name'], $request['order'][0]['dir'])
            ->count('*');

        $sourceTemplates = $this->sourceTemplatesRepository
            ->tableFilter($request['search']['value'], $request['columns'][$request['order'][0]['column']]['name'], $request['order'][0]['dir'], (int)$request['length'], (int)$request['start'])
            ->fetchAll();

        $result = [
            'recordsTotal' => $this->sourceTemplatesRepository->totalCount(),
            'recordsFiltered' => $sourceTemplatesCount,
            'data' => []
        ];

        /** @var ActiveRow $sourceTemplate */
        foreach ($sourceTemplates as $sourceTemplate) {
            $editUrl = $this->link('Edit', $sourceTemplate->id);
            $generateUrl = $this->link('Generate', $sourceTemplate->id);
            $result['data'][] = [
                'actions' => [
                    'edit' => $editUrl,
                    'generate' => $generateUrl,
                ],
                $sourceTemplate->created_at,
                "<a href='{$editUrl}'>{$sourceTemplate->title}</a>",
                "<code>{$sourceTemplate->code}</code>",
                "<code>{$sourceTemplate->generator}</code>",
            ];
        }
        $this->presenter->sendJson($result);
    }

    public function renderEdit($id): void
    {
        $generator = $this->sourceTemplatesRepository->find($id);
        if (!$generator) {
            throw new BadRequestException();
        }
        $this->template->generator = $generator;
    }

    public function renderGenerate($id): void
    {
        $this->redirect("MailGenerator:default", ['source_template_id' => $id]);
    }

    public function createComponentMailSourceTemplateForm(): Form
    {
        $form = $this->sourceTemplateFormFactory->create(isset($this->params['id']) ? (int)$this->params['id'] : null);

        $this->sourceTemplateFormFactory->onUpdate = function ($form, $mailSourceTemplate, $buttonSubmitted) {
            $this->flashMessage('Source template was successfully updated');
            $this->redirectBasedOnButtonSubmitted($buttonSubmitted, $mailSourceTemplate->id);
        };
        $this->sourceTemplateFormFactory->onSave = function ($form, $mailSourceTemplate, $buttonSubmitted) {
            $this->flashMessage('Source template was successfully created');
            $this->redirectBasedOnButtonSubmitted($buttonSubmitted, $mailSourceTemplate->id);
        };
        return $form;
    }
}
