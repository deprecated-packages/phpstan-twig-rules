<?php

declare(strict_types=1);

namespace Symplify\PHPStanTwigRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Registry;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use Symplify\PHPStanRules\Rules\AbstractSymplifyRule;
use Symplify\PHPStanTwigRules\NodeAnalyzer\SymfonyRenderWithParametersMatcher;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Symplify\SmartFileSystem\SmartFileSystem;
use Symplify\TemplatePHPStanCompiler\ErrorSkipper;
use Symplify\TemplatePHPStanCompiler\PHPStan\FileAnalyserProvider;
use Symplify\TemplatePHPStanCompiler\Reporting\TemplateErrorsFactory;
use Symplify\TemplatePHPStanCompiler\TypeAnalyzer\TemplateVariableTypesResolver;
use Symplify\TemplatePHPStanCompiler\ValueObject\RenderTemplateWithParameters;
use Symplify\TemplatePHPStanCompiler\ValueObject\VariableAndType;
use Symplify\TwigPHPStanCompiler\TwigToPhpCompiler;

/**
 * @see \Symplify\PHPStanTwigRules\Tests\Rules\TwigCompleteCheckRule\TwigCompleteCheckRuleTest
 */
final class TwigCompleteCheckRule extends AbstractSymplifyRule
{
    /**
     * @var string
     */
    public const ERROR_MESSAGE = 'Complete analysis of PHP code generated from Twig template';

    /**
     * List of errors, that do not bring any value.
     *
     * @var string[]
     */
    private const ERROR_IGNORES = [
        '#Method __TwigTemplate(.*?)::doDisplay\(\) throws checked exception Twig\\\\Error\\\\RuntimeError#',
        '#Call to method (getSourceContext|loadTemplate)\(\) on an unknown class __TwigTemplate(.*?)#',
        '#Use separate function calls with readable variable names#',
        '#Property __TwigTemplate_(.*?)\:\:\$source is never read, only written#',
        '#Separate function "array_merge\(\)" in method call to standalone row to improve readability#',
        '#Function "extract\(\)" cannot be used/left in the code#',
        '#Array method calls \[\$this, "method"\] are not allowed\. Use explicit method instead to help PhpStorm, PHPStan and Rector understand your code#',
        '#Access to property \$(parent|source|macros|blocks) on an unknown class __TwigTemplate_(.*?)#',
        '#Class __TwigTemplate_(.*?) was not found while trying to analyse it \- discovering symbols is probably not configured properly#',
        '#Do not use chained method calls\. Put each on separated lines#',
        '#Access to property \$env on an unknown class __TwigTemplate_(.*?)#',
        // ob_start contents magic on {% set %} ...
        '#Anonymous function should have native return typehint "string"#',
        '#Parameter "blocks" cannot have default value#',
        '#Separate function "twig_array_merge\(\)" in method call to standalone row to improve readability#',
    ];

    private Registry $registry;

    /**
     * @param Rule[] $rules
     */
    public function __construct(
        array $rules,
        private SymfonyRenderWithParametersMatcher $symfonyRenderWithParametersMatcher,
        private TwigToPhpCompiler $twigToPhpCompiler,
        private SmartFileSystem $smartFileSystem,
        private FileAnalyserProvider $fileAnalyserProvider,
        private ErrorSkipper $errorSkipper,
        private TemplateVariableTypesResolver $templateVariableTypesResolver,
        private TemplateErrorsFactory $templateErrorsFactory,
    ) {
        $this->registry = new Registry($rules);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     * @return array<string|RuleError>
     */
    public function process(Node $node, Scope $scope): array
    {
        // 1. find twig template file path with array
        $renderTemplateWithParameters = $this->symfonyRenderWithParametersMatcher->matchTwigRender($node, $scope);
        if (! $renderTemplateWithParameters instanceof RenderTemplateWithParameters) {
            return [];
        }

        // 2. resolve passed variable types
        $variablesAndTypes = $this->templateVariableTypesResolver->resolveArray(
            $renderTemplateWithParameters->getParametersArray(),
            $scope
        );

        // 3. compile twig to PHP with resolved types in @var docs
        $ruleErrors = [];
        foreach ($renderTemplateWithParameters->getTemplateFilePaths() as $templateFilePath) {
            $currentRuleErrors = $this->processTemplateFilePath(
                $templateFilePath,
                $variablesAndTypes,
                $scope,
                $node->getLine()
            );
            $ruleErrors = array_merge($ruleErrors, $currentRuleErrors);
        }

        return $ruleErrors;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(self::ERROR_MESSAGE, [
            new CodeSample(
                <<<'CODE_SAMPLE'
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class SomeController extends AbstractController
{
    public function __invoke()
    {
        return $this->render(__DIR__ . '/some_file.twig', [
            'some' => new SomeObject()
        ]);
    }
}

// some_file.twig
{{ some.non_existing_method }}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class SomeController extends AbstractController
{
    public function __invoke()
    {
        return $this->render(__DIR__ . '/some_file.twig', [
            'some' => new SomeObject()
        ]);
    }
}

// some_file.twig
{{ some.existing_method }}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @param VariableAndType[] $variablesAndTypes
     * @return RuleError[]
     */
    private function processTemplateFilePath(
        string $templateFilePath,
        array $variablesAndTypes,
        Scope $scope,
        int $phpLine
    ): array {
        $phpFileContentsWithLineMap = $this->twigToPhpCompiler->compileContent($templateFilePath, $variablesAndTypes);

        $phpFileContents = $phpFileContentsWithLineMap->getPhpFileContents();

        // 4. print the content to temporary file
        $tmpFilePath = sys_get_temp_dir() . '/' . md5($scope->getFile()) . '-twig-compiled.php';
        $this->smartFileSystem->dumpFile($tmpFilePath, $phpFileContents);

        // 5. get file analyser
        $fileAnalyser = $this->fileAnalyserProvider->provide();

        // 6. analyse temporary PHP file with full PHPStan rules
        $fileAnalyserResult = $fileAnalyser->analyseFile($tmpFilePath, [], $this->registry, null);
        $ruleErrors = $this->errorSkipper->skipErrors($fileAnalyserResult->getErrors(), self::ERROR_IGNORES);

        return $this->templateErrorsFactory->createErrors(
            $ruleErrors,
            $scope->getFile(),
            $templateFilePath,
            $phpFileContentsWithLineMap,
            $phpLine
        );
    }
}
