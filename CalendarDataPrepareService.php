<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Model\CalendarEventResponseModel;
use App\Constant\CalendarEventType;
use App\Model\TimeSlotResponseModel;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Exception;
use App\Entity\UserEvent;
use App\Entity\Constants\ModerationStatus;
use App\Entity\User;

class CalendarDataPrepareService
{
    /**
     * @param array $eventList
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param int $duration
     * @return array
     */
    public function fillSlotResponse(
        array $eventList,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        int $duration = 15): array
    {
        $result = [];
        $now = new DateTimeImmutable();
        $interval = new DateInterval(TimeSlotResponseModel::TIME_SLOT_DURATION);

        // свободное время
        $period = new DatePeriod($startTime, $interval, $endTime);

        foreach ($period as $dateTime) {
            if($dateTime->getTimestamp() < $now->getTimestamp()) {
                continue;
            }
            $result[$dateTime->getTimestamp()] = (new TimeSlotResponseModel())
                ->setStartTime($dateTime)
                ->setIsAvailable(true)
            ;
        }

        // закрытые для назначения встреч слоты
        foreach ($eventList as $event) {
            $busyStartTime = $event->getStartAt()->modify('-'. $duration .' minutes');
            $period = new DatePeriod($busyStartTime, $interval, $event->getEndAt());

            foreach ($period as $dateTime) {
                if($dateTime->getTimestamp() === $busyStartTime->getTimestamp()) {
                    continue;
                }
                if($dateTime->getTimestamp() === $event->getEndAt()->getTimestamp()) {
                    continue;
                }
                if(true === array_key_exists($dateTime->getTimestamp(), $result)) {
                    $result[$dateTime->getTimestamp()] = (new TimeSlotResponseModel())
                        ->setStartTime($dateTime)
                        ->setIsAvailable(false);
                }
            }
        }

        return array_values($result);
    }

    /**
     * Удаление из массива пересекающихся со встречами эфиров/вебинаров
     * @param CalendarEventResponseModel[] $objectList
     * @return array
     */
    public function removeOverlapEvents(array $objectList): array
    {
        foreach (array_keys($objectList) as $key) {
            if ( array_key_exists($key, $objectList)
                && $objectList[$key]->getType() === CalendarEventType::TYPE_USER_EVENT
            ){
                $previousKey = $key - 1;
                $nextKey = $key + 1;

                if (array_key_exists($previousKey, $objectList)
                    && $objectList[$previousKey]->getType() !== CalendarEventType::TYPE_USER_EVENT
                    && $this->isObjectCrossByDate($objectList[$key], $objectList[$previousKey])
                ) {
                    unset($objectList[$previousKey]);
                }

                if (array_key_exists($nextKey, $objectList)
                    && $objectList[$nextKey]->getType() !== CalendarEventType::TYPE_USER_EVENT
                    && $this->isObjectCrossByDate($objectList[$key], $objectList[$nextKey])
                ) {
                    unset($objectList[$nextKey]);
                }
            }
        }

        return array_values($objectList);
    }

    /**
     * Подготавливает массив данных для userEvent
     * @param iterable $objectList
     * @param User $user
     * @return array
     */
    public function prepareEventList (iterable $objectList, User $user): array
    {
        $result = [];
        foreach ($objectList as $object) {
            $result[] = (new CalendarEventResponseModel())
                ->setId($object->getId())
                ->setTitle($object->getTitle())
                ->setIsOwner($this->isOwner($object, $user))
                ->setType(CalendarEventType::TYPE_USER_EVENT)
                ->setUserEventType($object->getType())
                ->setStatus($this->getAcceptedStatus($object))
                ->setDescription($object->getDescription())
                ->setStartAt($object->getStartAt())
                ->setEndAt($object->getEndAt())
            ;
        }

        return $result;
    }

    /**
     * Подготавливает массив данных для Webinar
     * @param iterable $objectList
     * @return array
     */
    public function prepareWebinarList (iterable $objectList): array
    {
        $result = [];
        foreach ($objectList as $object) {
            $result[] = (new CalendarEventResponseModel())
                ->setId($object->getWebinar()->getId())
                ->setTitle($object->getWebinar()->getTitle())
                ->setIsOwner(false)
                ->setType(CalendarEventType::TYPE_WEBINAR)
                ->setUserEventType(null)
                ->setStatus(true)
                ->setDescription($object->getWebinar()->getDescription())
                ->setStartAt($object->getWebinar()->getDate())
                ->setEndAt($object->getWebinar()->getDateClose())
            ;
        }

        return $result;
    }

    /**
     * Подготавливает массив данных для Broadcast
     * @param iterable $objectList
     * @return array
     */
    public function prepareBroadcastList(iterable $objectList): array
    {
        $result = [];
        foreach ($objectList as $object) {
            $result[] = (new CalendarEventResponseModel())
                ->setId($object->getBroadcast()->getId())
                ->setTitle($object->getBroadcast()->getTitle())
                ->setIsOwner(false)
                ->setType(CalendarEventType::TYPE_BROADCAST)
                ->setUserEventType(null)
                ->setStatus(true)
                ->setDescription($object->getBroadcast()->getShortDescription())
                ->setStartAt($object->getBroadcast()->getDate())
                ->setEndAt($object->getBroadcast()->getDateClose())
            ;
        }

        return $result;
    }

    /**
     * @param array $objList
     * @return mixed
     */
    public function sortItemListByStartAt(array $objList) : array
    {
        // sort by startDate
        usort($objList, static function($a, $b) {
            if ($a->getStartAt() === $b->getStartAt()) {
                return 0;
            }
            return $a->getStartAt() < $b->getStartAt() ? -1 : 1;
        });

        return array_values($objList);
    }

    /**
     * @param UserEvent $event
     * @return bool|null
     * @TODO переместить статусы в модель участников
     */
    private function getAcceptedStatus(UserEvent $event): ?bool
    {
        if($event->getParticipants()->isEmpty()) {
            return null;
        }

        $status = null;
        foreach ($event->getParticipants() as $item) {
            if($this->isOwner($event, $item->getUser())) {
                continue;
            }

            if($item->getStatus() === ModerationStatus::APPROVED){
                return true;
            }
            if($item->getStatus() === ModerationStatus::DENIED){
                $status = false;
            }
        }
        return $status;
    }

    /**
     * @param $object
     * @param User $user
     * @return bool
     */
    private function isOwner($object, User $user): bool
    {
        if( method_exists($object, 'getOwner') && null !== $object->getOwner() ) {
            return $object->getOwner()->getId() === $user->getId();
        }
        return false;
    }

    /**
     * @param $object
     * @param $compareObject
     * @return bool
     */
    private function isObjectCrossByDate($object, $compareObject): bool
    {
        return ($object->getStartAt() >= $compareObject->getStartAt() && $object->getStartAt() <= $compareObject->getEndAt())
            || ($object->getEndAt() >= $compareObject->getStartAt() && $object->getEndAt() <= $compareObject->getEndAt());
    }

}