<?php

declare(strict_types=1);

namespace App\Controller\ApiSpa\User;

use App\Repository\UserEventRepository;
use App\Service\TranslationService;
use App\Service\User\CalendarDataPrepareService;
use App\Service\RequestService;
use App\Service\ResponseService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use OpenApi\Annotations as OA;

/**
 * @Route("/user",
 *     condition="request.isXmlHttpRequest()",
 * )
 *
 * @OA\Tag(name="User calendar")
 *
 * @Security("is_granted('ROLE_USER') && user.getParameter().getIsNetworking()")
 */
class UserCalendarController extends AbstractController
{

    /**
     * Начало дня для назначения встреч
     */
    public const START_EVENT_HOUR = 8;

    /**
     * Конец дня для назначения встреч
     */
    public const END_EVENT_HOUR = 20;

    protected CalendarDataPrepareService $prepareEventData;

    protected ResponseService $responseService;

    protected UserEventRepository $userEventRepository;

    private EntityManagerInterface $entityManager;

    private TranslationService $translationService;

    public function __construct(
        CalendarDataPrepareService $prepareEventData,
        ResponseService $responseService,
        UserEventRepository $userEventRepository,
        EntityManagerInterface $entityManager,
        TranslationService $translationService
    )
    {
        $this->prepareEventData = $prepareEventData;
        $this->responseService = $responseService;
        $this->userEventRepository = $userEventRepository;
        $this->entityManager = $entityManager;
        $this->translationService = $translationService;
    }


    /**
     * Получить события календаря для текущего пользователем
     *
     * @Route("/calendar/list",
     *     methods={"GET"}
     * )
     *
     * @OA\Get (
     *      @OA\Header(
     *        header="X-Requested-With",
     *        required=true,
     *        description="XMLHttpRequest",
     *        @OA\Schema(
     *          type="string",
     *          default="XMLHttpRequest",
     *        )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="Get user event list"
     *      ),
     *     @OA\Parameter(
     *          name="dateStart",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="Start period date, empty=now"
     *     ),
     *     @OA\Parameter(
     *          name="dateEnd",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="End period date, empty=now"
     *     )
     * )
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function listAction(Request $request): JsonResponse
    {
        $dateStart = $request->get('dateStart');
        $dateEnd = $request->get('dateEnd');

        /** @var User $user */
        $user = $this->getUser();

        $dateStart = $dateStart ? new DateTimeImmutable($dateStart) : null;
        $dateEnd = $dateEnd ? new DateTimeImmutable($dateEnd) : null;

        // UserEvents
        $eventList = $this->userEventRepository->getUserCalendarEventList(
            $user,
            $request->getLocale(),
            $dateStart,
            $dateEnd
        )
            ->getQuery()
            ->getResult();

        $result = $this->prepareEventData->prepareEventList($eventList, $user);

        // UserWebinars
        $webinarList =  [];
        $result = array_merge($result, $this->prepareEventData->prepareWebinarList($webinarList));

        // UserBroadcasts
        $broadcastList =  [];

        $result = array_merge($result, $this->prepareEventData->prepareBroadcastList($broadcastList));

        $result = $this->prepareEventData->sortItemListByStartAt($result);
        $result = $this->prepareEventData->removeOverlapEvents($result);

        return $this->json(...$this->responseService->success($result));
    }

    /**
     * Получение доступных интервалов в течении дня
     *
     * @Route("/{toUserId}/calendar/free",
     *     requirements={"toUserId": "\d+"},
     *     methods={"GET"}
     * )
     * @OA\Get (
     *      @OA\Header(
     *        header="X-Requested-With",
     *        required=true,
     *        description="XMLHttpRequest",
     *        @OA\Schema(
     *          type="string",
     *          default="XMLHttpRequest",
     *        )
     *     ),
     *     @OA\Response(
     *          response=200,
     *          description="array of slot period"
     *      ),
     *     @OA\Parameter(
     *          name="toUserId",
     *          in="path",
     *          @OA\Schema(type="integer"),
     *          description="Id of target user"
     *     ),
     *     @OA\Parameter(
     *          name="day",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="Query day, empty=now"
     *     ),
     *     @OA\Parameter(
     *          name="duration",
     *          in="query",
     *          @OA\Schema(type="integer"),
     *          description="Slot duration, empty=15"
     *     )
     * )
     *
     * @param Request $request
     * @param int $toUserId
     * @return JsonResponse
     * @throws Exception
     */
    public function eventSlot(Request $request, int $toUserId): JsonResponse
    {
        /** @var User $toUser */
        $toUser = $this->entityManager->getRepository(User::class)->findOneBy(['id' => $toUserId]);
        if (null === $toUser) {
            return $this->json(...$this->responseService->error(
                $this->translationService->getStaticTranslateFromFile(ResponseService::USER_NOT_FOUND, 'messages'),
                Response::HTTP_NOT_ACCEPTABLE
            ));
        }

        /** @var User $user */
        $user = $this->getUser();
        $duration = (int) $request->get('duration', '15');

        $day = new DateTimeImmutable($request->get('day', 'now'));
        $now = new DateTimeImmutable('now');

        // исключение прошедших слотов
        $startTime = $day->setTime(self::START_EVENT_HOUR,0,0);
        if($startTime->getTimestamp() <= $now->getTimestamp()) {
            $startTime = $day->setTime( (int) $now->format('H'), 0, 0);
        }

        $endTime = $day->setTime(self::END_EVENT_HOUR, 0, 0);

        $userEventList = $this->userEventRepository->getOpenUserEventListInPeriodQB(
            $user,
            $toUser,
            $startTime,
            $endTime
        )
            ->getQuery()
            ->getResult()
        ;

        $result = $this->prepareEventData->fillSlotResponse($userEventList, $startTime, $endTime, $duration);

        return $this->json(...$this->responseService->success($result));
    }
}