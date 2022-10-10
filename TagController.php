<?php

declare(strict_types=1);

namespace App\Controller\ApiSpa;

use App\Entity\TagScope;
use App\Entity\TagSpecialization;
use App\Repository\TagRepositoryInterface;
use App\Service\ResponseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * @Route("/tag",
 *     condition="request.isXmlHttpRequest()"
 * )
 *
 * @OA\Get (
 *      @OA\Header(
 *        header="X-Requested-With",
 *        required=true,
 *        description="XMLHttpRequest"
 *      )
 * )
 * 
 * @OA\Tag(name="Tags")
 */

class TagController extends AbstractController
{
    /**
     * @var ResponseService
     */
    protected ResponseService $responseService;

    public function __construct(ResponseService $responseService)
    {
        $this->responseService = $responseService;
    }

    /**
     * Список тегов по типу
     *
     * @Route("/{type}/list",
     *     methods={"GET"},
     *     requirements={
     *         "type": "specialization|scope",
     *     }
     * )
     * @OA\Parameter(
     *     name="type",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="string", enum={"specialization", "scope"}),
     *     description="type of tag. specialization/scope"
     * )
     *
     * @OA\Response(
     *     response=200,
     *     description="tag list"
     * )
     *
     * @param Request $request
     * @param string $type
     * @param EntityManagerInterface $entityManager
     * @return JsonResponse
     */
    public function tagListAction(Request $request, string $type, EntityManagerInterface $entityManager): JsonResponse
    {
        switch ($type) {
            case 'specialization':
                $className = TagSpecialization::class;
                break;
            case 'scope':
                $className = TagScope::class;
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Неизвестный тип тега "%s"', $type));
        }

        $repository = $entityManager->getRepository($className);

        if (!$repository instanceof TagRepositoryInterface) {
            throw new \BadFunctionCallException(sprintf('Репозиторий класса "%s" должен реализовывать интерфейс "%s"', $className, TagRepositoryInterface::class));
        }

        return $this->json(...$this->responseService->success($repository->getTagList($request->getLocale())));
    }
}