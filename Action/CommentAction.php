<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace Comment\Action;

use Comment\Comment as CommentModule;
use Comment\Events\CommentAbuseEvent;
use Comment\Events\CommentChangeStatusEvent;
use Comment\Events\CommentCheckOrderEvent;
use Comment\Events\CommentComputeRatingEvent;
use Comment\Events\CommentCreateEvent;
use Comment\Events\CommentDefinitionEvent;
use Comment\Events\CommentDeleteEvent;
use Comment\Events\CommentEvent;
use Comment\Events\CommentEvents;
use Comment\Events\CommentReferenceGetterEvent;
use Comment\Events\CommentUpdateEvent;
use Comment\Exception\InvalidDefinitionException;
use Comment\Model\Comment;
use Comment\Model\CommentQuery;
use Comment\Model\Map\CommentTableMap;
use DateInterval;
use DateTime;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Thelia\Action\BaseAction;
use Thelia\Core\Event\UpdatePositionEvent;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\Category;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Map\ProductCategoryTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\MessageQuery;
use Thelia\Model\MetaData;
use Thelia\Model\MetaDataQuery;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductQuery;
use Thelia\Tools\URL;

/**
 *
 * CommentAction class where all actions are managed
 *
 * Class CommentAction
 * @package Comment\Action
 * @author Michaël Espeche <michael.espeche@gmail.com>
 */
class CommentAction extends BaseAction implements EventSubscriberInterface
{
    /** @var null|TranslatorInterface */
    protected $translator = null;

    /** @var null|ParserInterface */
    protected $parser = null;

    /** @var null|MailerFactory */
    protected $mailer = null;

    public function __construct(TranslatorInterface $translator, ParserInterface $parser, MailerFactory $mailer)
    {
        $this->translator = $translator;
        $this->parser = $parser;
        $this->mailer = $mailer;
    }

    public function create(CommentCreateEvent $event)
    {
        $config = \Comment\Comment::getConfig();

        if ($config['only_one_comment_per_ref_per_customer']) {
            // check that the customer has not already posted a comment on this reference
            $existingComment = CommentQuery::create()
                ->filterByCustomerId($event->getCustomerId())
                ->filterByRef($event->getRef())
                ->filterByRefId($event->getRefId())
                ->findOne();

            if ($existingComment !== null) {
                throw new InvalidDefinitionException(
                    $this->translator->trans(
                        "You already commented this.",
                        [],
                        CommentModule::MESSAGE_DOMAIN
                    ),
                    false
                );
            }
        }

        $comment = new Comment();

        $comment
            ->setRef($event->getRef())
            ->setRefId($event->getRefId())
            ->setCustomerId($event->getCustomerId())
            ->setUsername($event->getUsername())
            ->setEmail($event->getEmail())
            ->setLocale($event->getLocale())
            ->setTitle($event->getTitle())
            ->setContent($event->getContent())
            ->setStatus($event->getStatus())
            ->setVerified($event->isVerified())
            ->setRating($event->getRating())
            ->setAbuse($event->getAbuse())
            ->setFeatured($event->isFeatured())
            ->setSeen($event->isSeen())
            ->save();

        $event->setComment($comment);

        if (Comment::ACCEPTED === $comment->getStatus()) {
            $this->dispatchRatingCompute(
                $event->getDispatcher(),
                $comment->getRef(),
                $comment->getRefId()
            );
        }
    }

    public function update(CommentUpdateEvent $event)
    {
        if (null !== $comment = CommentQuery::create()->findPk($event->getId())) {
            $comment
                ->setRef($event->getRef())
                ->setRefId($event->getRefId())
                ->setCustomerId($event->getCustomerId())
                ->setUsername($event->getUsername())
                ->setEmail($event->getEmail())
                ->setLocale($event->getLocale())
                ->setTitle($event->getTitle())
                ->setContent($event->getContent())
                ->setStatus($event->getStatus())
                ->setVerified($event->isVerified())
                ->setRating($event->getRating())
                ->setAbuse($event->getAbuse())
                ->setFeatured($event->isFeatured())
                ->setSeen($event->isSeen())
                ->save();
            $event->setComment($comment);

            $this->dispatchRatingCompute(
                $event->getDispatcher(),
                $comment->getRef(),
                $comment->getRefId()
            );
        }
    }

    public function delete(CommentDeleteEvent $event)
    {
        if (null !== $comment = CommentQuery::create()->findPk($event->getId())) {
            $comment->delete();

            $event->setComment($comment);

            if (Comment::ACCEPTED === $comment->getStatus()) {
                $this->dispatchRatingCompute(
                    $event->getDispatcher(),
                    $comment->getRef(),
                    $comment->getRefId()
                );
            }
        }
    }

    public function abuse(CommentAbuseEvent $event)
    {
        if (null !== $comment = CommentQuery::create()->findPk($event->getId())) {
            $comment->setAbuse($comment->getAbuse() + 1);
            $comment->save();

            $event->setComment($comment);
        }
    }

    public function statusChange(CommentChangeStatusEvent $event)
    {
        $changed = false;

        if (null !== $comment = CommentQuery::create()->findPk($event->getId())) {
            if ($comment->getStatus() !== $event->getNewStatus()) {
                $comment->setStatus($event->getNewStatus());
                $comment->save();

                $event->setComment($comment);

                $this->dispatchRatingCompute(
                    $event->getDispatcher(),
                    $comment->getRef(),
                    $comment->getRefId()
                );
            }
        }
    }

    public function updatePosition(UpdatePositionEvent $event)
    {
        $comment = CommentQuery::create()->findPk($event->getObjectId());
        if ($comment === null) {
            return;
        }

        switch ($event->getMode()) {
            case UpdatePositionEvent::POSITION_UP:
                $comment->moveUp();
                break;
            case UpdatePositionEvent::POSITION_DOWN:
                $comment->moveDown();
                break;
            case UpdatePositionEvent::POSITION_ABSOLUTE:
                $comment->moveToRank($event->getPosition());
                break;
        }

        $comment->save();
    }

    /**
     * Mark one comment (if an id is set in the event) or all comments as seen.
     *
     * @param CommentEvent $event Comment event
     * @throws PropelException
     */
    public function seen(CommentEvent $event)
    {
        $query = CommentQuery::create()->filterBySeen(0);

        if ($event->getId() !== null) {
            $query->filterById($event->getId());
        }

        $query->update([
            'Seen' => 1,
        ]);
    }

    /**
     * Compute all averages involving a product.
     *
     * @param CommentComputeRatingEvent $event Compute request event.
     */
    public function productRatingCompute(CommentComputeRatingEvent $event)
    {
        // only process compute requests for products
        if ('product' !== $event->getRef()) {
            return;
        }

        // only process compute requests that have a product
        $product = ProductQuery::create()->findPk($event->getRefId());
        if (null === $product) {
            return;
        }

        // average of ratings for the product
        $this->productRatingComputeForProduct($product, $event);

        // average of ratings for the product's categories
        foreach ($product->getCategories() as $category) {
            $this->productRatingComputeForCategory($category);
        }

        // average of ratings for all products
        $this->productRatingComputeForAll();
    }

    /**
     * Compute the average rating for a product.
     *
     * @param Product $product Product to compute the average for.
     * @param CommentComputeRatingEvent $event Event in which to set the computed value.
     */
    protected function productRatingComputeForProduct(Product $product, CommentComputeRatingEvent $event)
    {
        $query = CommentQuery::create()
            ->filterByRef('product')
            ->filterByRefId($product->getId());

        $rating = static::averageComments(
            $query,
            MetaData::PRODUCT_KEY,
            $product->getId()
        );

        $event->setRating($rating);
    }

    /**
     * Compute the average rating for all product in a category and its sub-categories.
     *
     * @param Category $category Category to compute the average for.
     */
    protected function productRatingComputeForCategory(Category $category)
    {
        // list this category and all its sub-categories
        $categories = $this->getSubCategories($category);

        $query = CommentQuery::create()
            ->filterByRef('product');

        $query->addJoin(
            CommentTableMap::REF_ID,
            ProductCategoryTableMap::PRODUCT_ID,
            Criteria::INNER_JOIN
        );

        $query->where(
            ProductCategoryTableMap::CATEGORY_ID . Criteria::IN . "('". implode("','", $categories) ."')"
        );

        $this->averageComments(
            $query,
            MetaData::PRODUCT_KEY . '-for-' . MetaData::CATEGORY_KEY,
            $category->getId()
        );

        // compute the rating for this category's parent
        $parentCategory = CategoryQuery::create()->findPk($category->getParent());
        if (null !== $parentCategory) {
            $this->productRatingComputeForCategory($parentCategory);
        }
    }

    /**
     * Get all sub categories, of any level, of a category.
     *
     * @param Category $rootCategory Root category.
     * @return array Ids of this category and all its sub categories.
     */
    protected function getSubCategories(Category $rootCategory)
    {
        $subCategories = [$rootCategory->getId()];

        /** @var Category $subCategory */
        foreach (CategoryQuery::create()->findByParent($rootCategory->getId()) as $subCategory) {
            $subCategories = array_merge($subCategories, $this->getSubCategories($subCategory));
        }

        return $subCategories;
    }

    /**
     * Compute the average rating for all products in the store.
     */
    protected function productRatingComputeForAll()
    {
        $query = CommentQuery::create()->filterByRef('product');

        $this->averageComments(
            $query,
            MetaData::PRODUCT_KEY . '-all',
            0
        );
    }

    /**
     * Average comment ratings from a query.
     * Store the average rating and the number of ratings into the meta-data table.
     *
     * @param CommentQuery $query Query of the comments to average.
     * @param string $metaElementKey Meta-data element key.
     * @param int $metaElementId Meta-data element id.
     * @return float The average rating.
     * @throws PropelException
     */
    protected function averageComments(CommentQuery $query, $metaElementKey, $metaElementId)
    {
        $query
            ->filterByStatus(Comment::ACCEPTED)
            ->withColumn("AVG(RATING)", 'rating')
            ->withColumn("COUNT(RATING)", 'count')
            ->select('rating', 'count');

        $rating = $query->findOne();

        MetaDataQuery::setVal(
            Comment::META_KEY_RATING,
            $metaElementKey,
            $metaElementId,
            $rating
        );

        return $rating['count'];
    }

    /**
     * Dispatch an event to compute an average rating
     *
     * @param string $ref
     * @param int $refId
     */
    protected function dispatchRatingCompute($dispatcher, $ref, $refId)
    {
        $ratingEvent = new CommentComputeRatingEvent();

        $ratingEvent
            ->setRef($ref)
            ->setRefId($refId);

        $dispatcher->dispatch(
            CommentEvents::COMMENT_RATING_COMPUTE,
            $ratingEvent
        );
    }

    public function getRefrence(CommentReferenceGetterEvent $event)
    {
        if ('product' === $event->getRef()) {
            $product = ProductQuery::create()->findPk($event->getRefId());
            if (null !== $product) {
                if (null !== $event->getLocale()) {
                    $product->setLocale($event->getLocale());
                }
                $event->setTypeTitle($this->translator->trans('Product', [], 'core', $event->getLocale()));
                $event->setTitle($product->getTitle());
                $event->setViewUrl($product->getUrl($event->getLocale()));
                $event->setEditUrl(
                    URL::getInstance()->absoluteUrl(
                        '/admin/products/update',
                        ['product_id' => $product->getId()]
                    )
                );
                $event->setObject($product);
            }
        } elseif ('content' === $event->getRef()) {
            $content = ContentQuery::create()->findPk($event->getRefId());
            if (null !== $content) {
                if (null !== $event->getLocale()) {
                    $content->setLocale($event->getLocale());
                }
                $event->setTypeTitle($this->translator->trans('Content', [], 'core', $event->getLocale()));
                $event->setTitle($content->getTitle());
                $event->setViewUrl($content->getUrl($event->getLocale()));
                $event->setEditUrl(
                    URL::getInstance()->absoluteUrl(
                        '/admin/contents/update',
                        ['product_id' => $content->getId()]
                    )
                );
                $event->setObject($content);
            }
        }
    }

    public function getDefinition(CommentDefinitionEvent $event)
    {
        $config = $event->getConfig();

        if (!in_array($event->getRef(), $config['ref_allowed'])) {
            throw new InvalidDefinitionException(
                $this->translator->trans(
                    "Reference %ref is not allowed",
                    ['%ref' => $event->getRef()],
                    CommentModule::MESSAGE_DOMAIN
                )
            );
        }

        $eventName = CommentEvents::COMMENT_GET_DEFINITION . "." . $event->getRef();
        $event->getDispatcher()->dispatch($eventName, $event);

        // is only customer is authorized to publish
        if ($config['only_customer'] && null === $event->getCustomer()) {
            throw new InvalidDefinitionException(
                $this->translator->trans(
                    "Only logged-in customers can publish comments.",
                    [],
                    CommentModule::MESSAGE_DOMAIN
                ),
                false
            );
        }

        if (null !== $event->getCustomer()) {
            // is customer already have published something
            $comment = CommentQuery::create()
                ->filterByCustomerId($event->getCustomer()->getId())
                ->filterByRef($event->getRef())
                ->filterByRefId($event->getRefId())
                ->findOne();

            if (null !== $comment) {
                if ($config['only_one_comment_per_ref_per_customer']) {
                    throw new InvalidDefinitionException(
                        $this->translator->trans(
                            "You already commented this.",
                            [],
                            CommentModule::MESSAGE_DOMAIN
                        ),
                        false
                    );
                }

                $event->setComment($comment);
            }
        }
    }


    public function getProductDefinition(CommentDefinitionEvent $event)
    {
        $config = $event->getConfig();

        $event->setRating(true);

        $product = ProductQuery::create()->findPk($event->getRefId());
        if (null === $product) {
            throw new InvalidDefinitionException(
                $this->translator->trans(
                    "Product %id does not exist",
                    ['%ref' => $event->getRef()],
                    CommentModule::MESSAGE_DOMAIN
                )
            );
        }

        // is comment is authorized on this product
        $commentProductActivated = MetaDataQuery::getVal(
            Comment::META_KEY_ACTIVATED,
            \Thelia\Model\MetaData::PRODUCT_KEY,
            $product->getId()
        );

        // not defined, get the global config
        if ("1" !== $commentProductActivated) {
            if ("0" === $commentProductActivated || false === $config['activated']) {
                throw new InvalidDefinitionException(
                    $this->translator->trans(
                        "Comment not activated on this element.",
                        ['%ref' => $event->getRef()],
                        CommentModule::MESSAGE_DOMAIN
                    )
                );
            }
        }

        $verified = false;
        if (null !== $event->getCustomer()) {
            // customer has bought the product
            $productBoughtCount = OrderProductQuery::getSaleStats(
                $product->getRef(),
                null,
                null,
                [2, 3, 4],
                $event->getCustomer()->getId()
            );

            if ($config['only_verified']) {
                if (0 === $productBoughtCount) {
                    throw new InvalidDefinitionException(
                        $this->translator->trans(
                            "Only customers who have bought this product can publish comments.",
                            [],
                            CommentModule::MESSAGE_DOMAIN
                        ),
                        false
                    );
                }
            }

            $verified = 0 !== $productBoughtCount;
        } else {
            $verified = false;
        }

        $event->setVerified($verified);
    }

    public function getContentDefinition(CommentDefinitionEvent $event)
    {
        $config = $event->getConfig();

        $event->setVerified(true);
        $event->setRating(false);

        // is comment is authorized on this product
        $commentProductActivated = MetaDataQuery::getVal(
            Comment::META_KEY_ACTIVATED,
            \Thelia\Model\MetaData::CONTENT_KEY,
            $event->getRefId()
        );

        // not defined, get the global config
        if ("1" !== $commentProductActivated) {
            if ("0" === $commentProductActivated || false === $config['activated']) {
                throw new InvalidDefinitionException(
                    $this->translator->trans(
                        "Comment not activated on this element.",
                        ['%ref' => $event->getRef()],
                        CommentModule::MESSAGE_DOMAIN
                    )
                );
            }
        }
    }

    public function requestCustomerDemand(CommentCheckOrderEvent $event)
    {
        $config = \Comment\Comment::getConfig();
        $nbDays = $config["request_customer_ttl"];

        if (0 !== $nbDays) {

            $endDate = new DateTime('NOW');
            $endDate->setTime(0, 0, 0);
            $endDate->sub(new DateInterval('P' . $nbDays . 'D'));

            $startDate = clone $endDate;
            $startDate->sub(new DateInterval('P1D'));

            $pseJoin = new Join(
                OrderProductTableMap::PRODUCT_SALE_ELEMENTS_ID,
                ProductSaleElementsTableMap::ID,
                Criteria::INNER_JOIN
            );

            $products = OrderProductQuery::create()
                ->useOrderQuery()
                ->filterByInvoiceDate($startDate, Criteria::GREATER_EQUAL)
                ->filterByInvoiceDate($endDate, Criteria::LESS_THAN)
                ->addAsColumn('customerId', OrderTableMap::CUSTOMER_ID)
                ->addAsColumn('orderId', OrderTableMap::ID)
                ->endUse()
                ->addJoinObject($pseJoin)
                ->addAsColumn('pseId', OrderProductTableMap::PRODUCT_SALE_ELEMENTS_ID)
                ->addAsColumn('productId', ProductSaleElementsTableMap::PRODUCT_ID)
                ->select(
                    [
                        'customerId',
                        'orderId',
                        'pseId',
                        'productId'
                    ]
                )
                ->find()
                ->toArray();

            if (empty($products)) {
                return;
            }

            $customerProducts = array_reduce(
                $products,
                function ($result, $item) {

                    if (!array_key_exists($item['customerId'], $result)) {
                        $result[$item['customerId']] = [];
                    }
                    if (!in_array($item['productId'], $result[$item['customerId']])) {
                        $result[$item['customerId']][] = $item['productId'];
                    }

                    return $result;
                },
                []
            );

            $customerIds = array_keys($customerProducts);

            // check if comments already exists
            $comments = CommentQuery::create()
                ->filterByCustomerId($customerIds)
                ->filterByRef(MetaData::PRODUCT_KEY)
                ->addAsColumn('customerId', CommentTableMap::CUSTOMER_ID)
                ->addAsColumn('productId', CommentTableMap::REF_ID)
                ->select(
                    [
                        'customerId',
                        'productId'
                    ]
                )
                ->find()
                ->toArray();

            $customerComments = array_reduce(
                $comments,
                function ($result, $item) {

                    if (!array_key_exists($item['customerId'], $result)) {
                        $result[$item['customerId']] = [];
                    }
                    $result[$item['customerId']][] = $item['productId'];

                    return $result;
                },
                []
            );

            foreach ($customerIds as $customerId) {
                $send = false;

                if (!array_key_exists($customerId, $customerComments)) {
                    $send = true;
                } else {
                    $noCommentsPosted = array_intersect(
                        $customerComments[$customerId],
                        $customerProducts[$customerId]
                    );

                    if (empty($noCommentsPosted)) {
                        $send = true;
                    }
                }

                if ($send) {
                    try {
                        $this->sendCommentRequestCustomerMail($customerId, $customerProducts[$customerId]);
                    } catch (\Exception $ex) {
                        Tlog::getInstance()->error($ex->getMessage());
                    }
                }
            }
        }
    }

    protected function sendCommentRequestCustomerMail($customerId, array $productIds)
    {
        $contact_email = ConfigQuery::getStoreEmail();

        if ($contact_email) {
            $message = MessageQuery::create()
                ->filterByName('comment_request_customer')
                ->findOne();

            if (null === $message) {
                throw new \Exception("Failed to load message 'comment_request_customer'.");
            }

            $customer = CustomerQuery::create()->findPk($customerId);

            if (null === $customer) {
                throw new \Exception(
                    sprintf("Failed to load customer '%s'.", $customerId)
                );
            }

            $parser = $this->parser;

            $locale = $customer->getCustomerLang()->getLocale();

            $parser->assign('customer_id', $customer->getId());
            $parser->assign('product_ids', $productIds);
            $parser->assign('lang_id', $customer->getCustomerLang()->getId());

            $message->setLocale($locale);

            $instance = \Swift_Message::newInstance()
                ->addTo($customer->getEmail(), $customer->getFirstname() . " " . $customer->getLastname())
                ->addFrom($contact_email, ConfigQuery::getStoreName());

            // Build subject and body
            $message->buildMessage($parser, $instance);

            $this->mailer->send($instance);

            Tlog::getInstance()->debug(
                "Message sent to customer " . $customer->getEmail() . " to ask for comments"
            );
        }
    }

    /**
     * Notify shop managers of a new comment.
     * @param CommentCreateEvent $event
     */
    public function notifyAdminOfNewComment(CommentCreateEvent $event)
    {
        $config = \Comment\Comment::getConfig();
        if (!$config["notify_admin_new_comment"]) {
            return;
        }

        $comment = $event->getComment();
        if ($comment === null) {
            return;
        }

        // get the default shop locale
        $shopLang = LangQuery::create()->findOneByByDefault(true);
        if ($shopLang !== null) {
            $shopLocale = $shopLang->getLocale();
        } else {
            $shopLocale = null;
        }

        $getCommentRefEvent = new CommentReferenceGetterEvent(
            $comment->getRef(),
            $comment->getRefId(),
            $shopLocale
        );
        $event->getDispatcher()->dispatch(CommentEvents::COMMENT_REFERENCE_GETTER, $getCommentRefEvent);

        $this->mailer->sendEmailToShopManagers(
            'new_comment_notification_admin',
            [
                'comment_id' => $comment->getId(),
                'ref_title' => $getCommentRefEvent->getTitle(),
                'ref_type_title' => $getCommentRefEvent->getTypeTitle(),
            ]
        );
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     *  * The method name to call (priority defaults to 0)
     *  * An array composed of the method name to call and the priority
     *  * An array of arrays composed of the method names to call and respective
     *    priorities, or 0 if unset
     *
     * For instance:
     *
     *  * array('eventName' => 'methodName')
     *  * array('eventName' => array('methodName', $priority))
     *  * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     *
     * @api
     */
    public static function getSubscribedEvents()
    {
        return [
            CommentEvents::COMMENT_CREATE => [
                ['create', 128],
                ['notifyAdminOfNewComment', 64],
            ],
            CommentEvents::COMMENT_DELETE => ['delete', 128],
            CommentEvents::COMMENT_UPDATE => ['update', 128],
            CommentEvents::COMMENT_ABUSE => ['abuse', 128],
            CommentEvents::COMMENT_STATUS_UPDATE => ['statusChange', 128],
            CommentEvents::COMMENT_POSITION_UPDATE => ['updatePosition', 128],
            CommentEvents::COMMENT_SEEN => ['seen', 128],
            CommentEvents::COMMENT_RATING_COMPUTE => ['productRatingCompute', 128],
            CommentEvents::COMMENT_REFERENCE_GETTER => ['getRefrence', 128],
            CommentEvents::COMMENT_CUSTOMER_DEMAND => ['requestCustomerDemand', 128],
            CommentEvents::COMMENT_GET_DEFINITION => ['getDefinition', 128],
            CommentEvents::COMMENT_GET_DEFINITION_PRODUCT => ['getProductDefinition', 128],
            CommentEvents::COMMENT_GET_DEFINITION_CONTENT => ['getContentDefinition', 128],
        ];
    }
}
