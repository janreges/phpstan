<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Name;
use PHPStan\Type\ArrayType;
use PHPStan\Type\CallableType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IterableIterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ResourceType;
use PHPStan\Type\StaticType;
use PHPStan\Type\StringType;
use PHPStan\Type\TrueOrFalseBooleanType;

class TypeSpecifier
{

	const SOURCE_UNKNOWN = 0;
	const SOURCE_FROM_AND = 1;
	const SOURCE_FROM_OR = 2;

	/**
	 * @var \PhpParser\PrettyPrinter\Standard
	 */
	private $printer;

	public function __construct(\PhpParser\PrettyPrinter\Standard $printer)
	{
		$this->printer = $printer;
	}

	public function specifyTypesInCondition(
		SpecifiedTypes $types,
		Scope $scope,
		Node $expr,
		bool $negated = false,
		int $source = self::SOURCE_UNKNOWN
	): SpecifiedTypes
	{
		if ($expr instanceof Instanceof_ && $expr->class instanceof Name) {
			$class = (string) $expr->class;
			if ($class === 'self' && $scope->isInClass()) {
				$type = new ObjectType($scope->getClassReflection()->getName());
			} elseif ($class === 'static' && $scope->isInClass()) {
				$type = new StaticType($scope->getClassReflection()->getName());
			} else {
				$type = new ObjectType($class);
			}

			$printedExpr = $this->printer->prettyPrintExpr($expr->expr);

			if ($negated) {
				if ($source === self::SOURCE_FROM_AND) {
					return $types;
				}
				return $types->addSureNotType($expr->expr, $printedExpr, $type);
			}

			return $types->addSureType($expr->expr, $printedExpr, $type);
		} elseif (
			$expr instanceof Node\Expr\BinaryOp\NotIdentical
			&& $expr->right instanceof ConstFetch
			&& $expr->right->name instanceof Name
			&& strtolower((string) $expr->right->name) === 'null'
		) {
			$printedExpr = $this->printer->prettyPrintExpr($expr->left);
			if ($negated) {
				if ($source === self::SOURCE_FROM_AND) {
					return $types;
				}
				return $types->addSureType($expr->left, $printedExpr, new NullType());
			}

			return $types->addSureNotType($expr->left, $printedExpr, new NullType());
		} elseif (
			$expr instanceof Node\Expr\BinaryOp\Identical
			&& $expr->right instanceof ConstFetch
			&& $expr->right->name instanceof Name
			&& strtolower((string) $expr->right->name) === 'null'
		) {
			$printedExpr = $this->printer->prettyPrintExpr($expr->left);
			if ($negated) {
				if ($source === self::SOURCE_FROM_AND) {
					return $types;
				}
				return $types->addSureNotType($expr->left, $printedExpr, new NullType());
			}

			return $types->addSureType($expr->left, $printedExpr, new NullType());
		} elseif (
			$expr instanceof FuncCall
			&& $expr->name instanceof Name
			&& isset($expr->args[0])
		) {
			$functionName = (string) $expr->name;
			$argumentExpression = $expr->args[0]->value;
			$specifiedType = null;
			if (in_array($functionName, [
				'is_int',
				'is_integer',
				'is_long',
			], true)) {
				$specifiedType = new IntegerType();
			} elseif (in_array($functionName, [
				'is_float',
				'is_double',
				'is_real',
			], true)) {
				$specifiedType = new FloatType();
			} elseif ($functionName === 'is_null') {
				$specifiedType = new NullType();
			} elseif ($functionName === 'is_array' && !($scope->getType($argumentExpression) instanceof ArrayType)) {
				$specifiedType = new ArrayType(new MixedType());
			} elseif ($functionName === 'is_bool') {
				$specifiedType = new TrueOrFalseBooleanType();
			} elseif ($functionName === 'is_callable') {
				$specifiedType = new CallableType();
			} elseif ($functionName === 'is_resource') {
				$specifiedType = new ResourceType();
			} elseif ($functionName === 'is_iterable') {
				$specifiedType = new IterableIterableType(new MixedType());
			} elseif ($functionName === 'is_string') {
				$specifiedType = new StringType();
			}

			if ($specifiedType !== null) {
				$printedExpr = $this->printer->prettyPrintExpr($argumentExpression);

				if ($negated) {
					return $types->addSureNotType($argumentExpression, $printedExpr, $specifiedType);
				}

				return $types->addSureType($argumentExpression, $printedExpr, $specifiedType);
			}
		} elseif ($expr instanceof BooleanAnd) {
			if ($source !== self::SOURCE_UNKNOWN && $source !== self::SOURCE_FROM_AND) {
				return $types;
			}
			$types = $this->specifyTypesInCondition($types, $scope, $expr->left, $negated, self::SOURCE_FROM_AND);
			$types = $this->specifyTypesInCondition($types, $scope, $expr->right, $negated, self::SOURCE_FROM_AND);
		} elseif ($expr instanceof BooleanOr) {
			if ($negated) {
				return $types;
			}
			$types = $this->specifyTypesInCondition($types, $scope, $expr->left, $negated, self::SOURCE_FROM_OR);
			$types = $this->specifyTypesInCondition($types, $scope, $expr->right, $negated, self::SOURCE_FROM_OR);
		} elseif ($expr instanceof Node\Expr\BooleanNot) {
			if ($source === self::SOURCE_FROM_AND) {
				return $types;
			}

			$types = $this->specifyTypesInCondition($types, $scope, $expr->expr, !$negated, $source);
		}

		return $types;
	}

}
