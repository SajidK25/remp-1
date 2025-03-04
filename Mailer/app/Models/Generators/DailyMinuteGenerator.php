<?php
declare(strict_types=1);

namespace Remp\Mailer\Models\Generators;

use Nette\Application\UI\Form;
use Nette\Utils\ArrayHash;
use Nette\Utils\DateTime;
use Remp\Mailer\Components\GeneratorWidgets\Widgets\DailyMinuteWidget\DailyMinuteWidget;
use Remp\MailerModule\Models\ContentGenerator\Engine\EngineFactory;
use Remp\MailerModule\Models\Generators\IGenerator;
use Remp\MailerModule\Models\Generators\InvalidUrlException;
use Remp\MailerModule\Models\Generators\PreprocessException;
use Remp\MailerModule\Repositories\SourceTemplatesRepository;
use Tomaj\NetteApi\Params\PostInputParam;

class DailyMinuteGenerator implements IGenerator
{
    public $onSubmit;

    public function __construct(
        private WordpressBlockParser $wordpressBlockParser,
        private SourceTemplatesRepository $sourceTemplatesRepository,
        private EngineFactory $engineFactory
    ) {
    }

    public function generateForm(Form $form): void
    {
        // disable CSRF protection as external sources could post the params here
        $form->offsetUnset(Form::PROTECTOR_ID);

        $form->addText('subject', 'Subject')
            ->setHtmlAttribute('class', 'form-control')
            ->setRequired();

        $form->addText('from', 'From')
            ->setHtmlAttribute('class', 'form-control');

        $form->addTextArea('blocks_json', 'Blocks JSON')
            ->setHtmlAttribute('rows', 8)
            ->setHtmlAttribute('class', 'form-control')
            ->setRequired();

        $form->onSuccess[] = [$this, 'formSucceeded'];
    }

    public function formSucceeded(Form $form, ArrayHash $values): void
    {
        try {
            $output = $this->process((array)$values);

            $addonParams = [
                'render' => true,
                'from' => $values->from,
                'subject' => $values->subject,
                'errors' => $output['errors'],
            ];

            $this->onSubmit->__invoke($output['htmlContent'], $output['textContent'], $addonParams);
        } catch (InvalidUrlException $e) {
            $form->addError($e->getMessage());
        }
    }

    public function onSubmit(callable $onSubmit): void
    {
        $this->onSubmit = $onSubmit;
    }

    public function getWidgets(): array
    {
        return [DailyMinuteWidget::class];
    }

    public function apiParams(): array
    {
        return [
            (new PostInputParam('url')),
            (new PostInputParam('subject'))->setRequired(),
            (new PostInputParam('blocks_json'))->setRequired(),
            (new PostInputParam('from')),
        ];
    }

    public function process(array $values): array
    {
        [$html, $text] = $this->wordpressBlockParser->parseJson($values['blocks_json']);

        $now = new DateTime();
        $additionalParams = [
            'date' => $now,
            'nameDay' => $this->getNameDayNamesForDate($now)
        ];

        $engine = $this->engineFactory->engine();
        $sourceTemplate = $this->sourceTemplatesRepository->find($values['source_template_id']);
        return [
            'htmlContent' => $engine->render($sourceTemplate->content_html, ['html' => $html] + $additionalParams),
            'textContent' => $engine->render($sourceTemplate->content_text, ['text' => $text] + $additionalParams),
            'from' => $values['from'],
            'subject' => $values['subject'],
            'errors' => []
        ];
    }

    public function preprocessParameters($data): ?ArrayHash
    {
        $output = new ArrayHash();

        if (!isset($data->blocks)) {
            throw new PreprocessException("WP json object does not contain required attribute 'blocks'");
        }

        if (!isset($data->subject)) {
            throw new PreprocessException("WP json object does not contain required attribute 'subject'");
        }

        $output->from = "Ranných 5 minút | Denník N <minuta@dennikn.sk>";

        $output->blocks_json = $data->blocks;
        $output->subject = $data->subject;

        return $output;
    }

    private function getNameDayNamesForDate(DateTime $date): string
    {
        $json = file_get_contents(__DIR__ . '/resources/namedays.json');
        $nameDays = json_decode($json, true);

        // javascript array of months in namedays.json starts from 0
        $month = ((int) $date->format('m')) - 1;
        $day = (int) $date->format('d');

        return $nameDays[$month][$day] ?? '';
    }
}
