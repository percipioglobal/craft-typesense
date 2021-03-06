<?php
/**
 * Typesense plugin for Craft CMS 3.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\controllers;

use craft\helpers\Queue;
use Http\Client\Exception;
use percipiolondon\typesense\jobs\SyncDocumentsJob;
use percipiolondon\typesense\Typesense;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;

use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\models\CollectionModel as Collection;
use percipiolondon\typesense\services\CollectionService;

use Typesense\Client as TypesenseClient;

use Typesense\Exceptions\TypesenseClientError;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Default Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 */
class CollectionsController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['create-collection', 'drop-collection', 'list-collections', 'retrieve-collection', 'index-documents', 'list-documents', 'delete-documents'];

    // Public Methods
    // =========================================================================

    /**
     * @throws InvalidConfigException
     * @throws ForbiddenHttpException
     */
    public function init()
    {
        parent::init();

        $this->requirePermission('typesense:manage-collections');
    }


    /**
     * Collections display
     *
     * @param string|null $siteHandle
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionCollections(string $siteHandle = null): Response
    {
        $variables = [];
        $entriesCount = [
            'entries' => [],
        ];

        $pluginName = Typesense::$settings->pluginName;
        $templateTitle = Craft::t('typesense', 'Collections');

        $variables['controllerHandle'] = 'collections';
        $variables['pluginName'] = Typesense::$settings->pluginName;
        $variables['title'] = $templateTitle;
        $variables['docTitle'] = "{$pluginName} - {$templateTitle}";
        $variables['selectedSubnavItem'] = 'collections';

        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            $entry = $index->criteria->one();
            $section = $entry->section ?? null;

            if($section) {
                $variables['sections'][] = [
                    'id' => $section->id,
                    'name' => $section->name,
                    'handle' => $section->handle,
                    'type' => $entry->type->handle,
                    'entryCount' => $index->criteria->count(),
                    'index' => $index->indexName
                ];
            }
        }

        $variables['csrf'] = [
            'name' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'value' => Craft::$app->getRequest()->getCsrfToken(),
        ];

        // Render the template
        return $this->renderTemplate('typesense/collections/index', $variables);
    }

    /**
     * @return Response
     */
    public function actionFlushCollection(): Response {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');
        $collection = Typesense::$plugin->collections->getCollectionByCollectionRetrieve($index);

        //delete collection
        if (!empty($collection)) {
            Typesense::$plugin->client->client()?->collections[$index]->delete();
        }

        Queue::push(new SyncDocumentsJob([
            'criteria' => [
                'index' => $index,
                'type' => 'Flush'
            ]
        ]));

        return $this->asJson([
            'success' => true
        ]);
    }

    /**
     * @return Response
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     * @throws BadRequestHttpException
     */
    public function actionSyncCollection(): Response {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');

        Queue::push(new SyncDocumentsJob([
            'criteria' => [
                'index' => $index,
                'type' => 'Sync'
            ]
        ]));

        return $this->asJson([
            'success' => true,
            'documents' =>  CollectionHelper::convertDocumentsToArray($index)
        ]);
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     */
//    public function actionSaveCollection(): Response {
//        $this->requirePostRequest();
//
//        $request = Craft::$app->getRequest();
//        $collection = CollectionHelper::collectionToSync($request);
//
//        // Always update the sync data ( after all the data has synced before re-writing in the database )
//        $collection->dateSynced = DateTimeHelper::toDateTime(DateTimeHelper::currentTimeStamp());
//
//        // Save the collection
//        if (!Typesense::$plugin->collections->saveCollection($collection)) {
//            // Response error
//            //$this->setFailFlash(Craft::t('typesense', 'Couldn’t save collection.'));
//
//            return $this->asJson([
//                'error' => Craft::t('typesense', 'Couldn’t save collection.'),
//            ]);
//        }
//
//        return $this->asJson($collection);
//    }

//    public function actionCreateCollection()
//    {
//
//        $schema = [
//            'name'      => 'news',
//            'fields'    => [
//                [
//                    'name'  => 'title',
//                    'type'  => 'string'
//                ],
//                [
//                    'name'  => 'slug',
//                    'type'  => 'string',
//                    'facet' => true
//                ],
//                [
//                    'name'  => 'dateCreated',
//                    'type'  => 'int32',
//
//                ]
//            ],
//            'default_sorting_field' => 'dateCreated' // can only be an integer
//        ];
//
//        if ( !Typesense::$plugin->client->client()?->collections['news'] ) {
//            Typesense::$plugin->client->client()?->collections->create($schema);
//            return 'index successfully created';
//        } else {
//            return 'this index already exists';
//        }
//
//    }
//
//    public function actionIndexDocuments()
//    {
//        $documents = [
//            [
//                'id' => '1',
//                'title' => 'Typesense Entry 1',
//                'slug' => 'typesense-entry-1',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '2',
//                'title' => 'Typesense Entry 2',
//                'slug' => 'typesense-entry-2',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '3',
//                'title' => 'Typesense Entry 3',
//                'slug' => 'typesense-entry-3',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '4',
//                'title' => 'Typesense Entry 4',
//                'slug' => 'typesense-entry-4',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '5',
//                'title' => 'Typesense Entry 5',
//                'slug' => 'typesense-entry-5',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '6',
//                'title' => 'Typesense Entry 6',
//                'slug' => 'typesense-entry-6',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '7',
//                'title' => 'Typesense Entry 7',
//                'slug' => 'typesense-entry-7',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '8',
//                'title' => 'Typesense Entry 8',
//                'slug' => 'typesense-entry-8',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '9',
//                'title' => 'Typesense Entry 9',
//                'slug' => 'typesense-entry-9',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '10',
//                'title' => 'Typesense Entry 10',
//                'slug' => 'typesense-entry-10',
//                'dateCreated' => 1639663729
//            ],
//        ];
//
//
//        if ( Typesense::$plugin->client->client()?->collections['news'] ) {
//            foreach ( $documents as $document) {
//                Typesense::$plugin->client->client()?->collections['news']->documents->upsert($document);
//            }
//            return 'All elements added to the index';
//        } else {
//            return 'this index doesn\'t exist';
//        }
//    }

    /**
     * @return Response
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionListDocuments(): Response
    {
        $index = $request->getBodyParam('index');

        if ( Typesense::$plugin->client->client()?->collections[$index] ) {
            return $this->asJson(Typesense::$plugin->client->client()?->collections[$index]->documents->export());
        } else {
            return 'this index doesn\'t exist';
        }
    }

    /**
     * @return string
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionDeleteDocuments()
    {
        $index = $request->getBodyParam('index');

        if ( Typesense::$plugin->client->client()?->collections[$index] ) {
            Typesense::$plugin->client->client()?->collections[$index]->documents->delete(['filter_by' => 'title: Typesense']);
        } else {
            return 'this index doesn\'t exist';
        }

    }

    /**
     * @return string
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionDropCollection()
    {
        $index = $request->getBodyParam('index');

        if ( Typesense::$plugin->client->client()?->collections[$index] ) {
            Typesense::$plugin->client->client()?->collections[$index]->delete();
            return 'index successfully deleted';
        } else {
            return 'this index doesn\'t exist';
        }
    }

    /**
     * @return Response
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionListCollections(): Response
    {
        return $this->asJson(Typesense::$plugin->client->client()?->collections->retrieve());
    }

    /**
     * @return Response
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionRetrieveCollection()
    {
        $index = $request->getBodyParam('index');

        return $this->asJson(Typesense::$plugin->client->client()?->collections[$index]->retrieve());
    }
}
