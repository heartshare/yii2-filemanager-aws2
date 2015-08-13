<?php

namespace dpodium\filemanager\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\web\Response;
use yii\helpers\Json;
use yii\web\UploadedFile;
use yii\helpers\ArrayHelper;
use yii\imagine\Image;
use dpodium\filemanager\models\Files;
use dpodium\filemanager\models\FilesSearch;
use dpodium\filemanager\models\Folders;
use dpodium\filemanager\models\FilesRelationship;
use dpodium\filemanager\models\FilesTag;
use dpodium\filemanager\components\Filemanager;
use dpodium\filemanager\FilemanagerAsset;
use dpodium\filemanager\components\S3;
use dpodium\filemanager\widgets\gallery\Gallery;

/**
 * FilesController implements the CRUD actions for Files model.
 */
class FilesController extends Controller {

    public function behaviors() {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Lists all Files models.
     * @return mixed
     */
    public function actionIndex($view = 'list') {
        FilemanagerAsset::register($this->view);

        $searchModel = new $this->module->models['filesSearch'];
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $folderArray = ArrayHelper::merge(['' => Yii::t('filemanager', 'All')], ArrayHelper::map(Folders::find()->all(), 'folder_id', 'category'));

        // lazy loading
        if ($view == 'grid' && \Yii::$app->request->isAjax) {
            echo Gallery::widget([
                'dataProvider' => $dataProvider,
                'viewFrom' => 'full-page'
            ]);
            \Yii::$app->end();
        }

        return $this->render('index', [
                    'model' => $searchModel,
                    'dataProvider' => $dataProvider,
                    'folderArray' => $folderArray,
                    'uploadType' => Filemanager::TYPE_FULL_PAGE,
                    'view' => $view,
                    'viewFrom' => 'full-page'
        ]);
    }

    /**
     * Updates an existing Files model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id, $view = '') {
        FilemanagerAsset::register($this->view);
        $model = $this->findModel($id);
        $tagArray = FilesRelationship::getTagIdArray($id);
        $model->tags = ArrayHelper::getColumn($tagArray, 'id');
        $editableTagsLabel = ArrayHelper::getColumn($tagArray, 'value');
        $tags = ArrayHelper::map(FilesTag::find()->asArray()->all(), 'tag_id', 'value');

        if (Yii::$app->request->post('hasEditable')) {
            $post = [];
            $post['Files'] = Yii::$app->request->post('Files');

            if ($model->load($post)) {
                foreach ($post['Files'] as $attribute => $value) {
                    if ($attribute === 'tags') {
                        $tagModel = new FilesTag();
                        $tagRelationshipModel = new FilesRelationship();
                        $saveTags = $tagModel->saveTag($model->tags);
                        $tagRelationshipModel->saveRelationship($model->file_id, $saveTags);
                        $editableTagsLabel = ArrayHelper::getColumn(FilesRelationship::getTagIdArray($id), 'value');
                        $result = Json::encode(['output' => implode(', ', $editableTagsLabel), 'message' => '']);
                    } else {
                        if ($model->update(true, [$attribute])) {
                            $model->touch('updated_at');
                            $result = Json::encode(['output' => $model->$attribute, 'message' => '']);
                        } else {
                            $result = Json::encode(['output' => $model->$attribute, 'message' => $model->errors[$attribute]]);
                        }
                    }
                }
            }
            echo $result;
            return;
        }

        if (Yii::$app->request->post('uploadType')) {
            echo $this->renderAjax('update', [
                'model' => $model,
                'tags' => $tags,
                'editableTagsLabel' => $editableTagsLabel,
                'uploadType' => 'modal',
                'view' => $view
            ]);
            return;
        } else {
            return $this->render('update', [
                        'model' => $model,
                        'tags' => $tags,
                        'editableTagsLabel' => $editableTagsLabel,
                        'uploadType' => 'full-page',
                        'view' => $view
            ]);
        }
    }

    /**
     * Deletes an existing Files model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id) {
        $model = $this->findModel($id);

        if (isset($this->module->storage['s3'])) {
            $files = [
                ['Key' => $model->url],
                ['Key' => $model->thumbnail_name],
            ];

            $s3 = new S3();
            $s3->delete($files);
        } else {
            $file = Yii::getAlias($model->storage_id) . $model->object_url . $model->src_file_name;
            $thumb = Yii::getAlias($model->storage_id) . $model->object_url . $model->thumbnail_name;

            if (file_exists($file)) {
                unlink($file);
            }

            if (file_exists($thumb)) {
                unlink($thumb);
            }
        }

        $model->delete();

        return $this->redirect(['index']);
    }

    public function actionUpload() {
        FilemanagerAsset::register($this->view);

        $model = new $this->module->models['files'];
        $model->scenario = 'upload';
        $folderArray = ArrayHelper::map(Folders::find()->all(), 'folder_id', 'category');

        if (Yii::$app->request->isAjax) {
            Yii::$app->response->getHeaders()->set('Vary', 'Accept');

            $file = UploadedFile::getInstances($model, 'upload_file');
            if (!$file) {
                echo Json::encode(['error' => Yii::t('filemanager', 'File not found.')]);
                \Yii::$app->end();
            }

            $uploadStatus = true;
            $model->folder_id = Yii::$app->request->post('uploadTo');
            $model->upload_file = $file[0];
            $model->src_file_name = $file[0]->name;
            $model->thumbnail_name = $file[0]->name;
            $model->mime_type = $file[0]->type;
            $folder = Folders::find()->select(['path', 'storage'])->where(['folder_id' => $model->folder_id])->one();

            if (!$folder) {
                echo Json::encode(['error' => Yii::t('filemanager', 'Invalid folder location.')]);
                \Yii::$app->end();
            }

            $model->url = '/' . $folder->path;
            $model->file_identifier = md5($folder->storage . $model->url . '/' . $model->src_file_name);
            $extension = '.' . $file[0]->getExtension();

            if (isset($this->module->storage['s3'])) {
                $model->object_url = '/';
                $model->host = isset($this->module->storage['s3']['host']) ? $this->module->storage['s3']['host'] : '';
                $model->storage_id = $this->module->storage['s3']['bucket'];
                $this->saveModel($model, $extension);
                $uploadStatus = $this->uploadToS3($model, $file[0], $extension);
            } else {
                $model->object_url = '/' . $folder->path . '/';
                $model->storage_id = $this->module->directory;
                $this->saveModel($model, $extension);
                $uploadStatus = $this->uploadToLocal($model, $file[0], $extension);
            }

            if (!$uploadStatus) {
                echo Json::encode(['error' => Yii::t('filemanager', 'Upload fail due to some reasons.')]);
                \Yii::$app->end();
            }

            // if upload type = 1, render edit bar below file input container
            // if upload type = 2, switch active tab to Library for user to select file
            Yii::$app->response->format = Response::FORMAT_JSON;
            if (Yii::$app->request->post('uploadType') == Filemanager::TYPE_FULL_PAGE) {
                $fileType = $model->mime_type;
                if ($model->dimension) {
                    $fileType = 'image';
                }
                $html = Filemanager::renderEditUploadedBar($model->file_id, $model->object_url, $model->src_file_name, $fileType);
                return ['status' => 1, 'message' => 'Upload Success', 'type' => Yii::$app->request->post('uploadType'), 'html' => $html];
            } else {
                return ['status' => 1, 'message' => 'Upload Success', 'type' => Yii::$app->request->post('uploadType')];
            }
            return;
        }

        return $this->render('upload', [
                    'model' => $model,
                    'folderArray' => $folderArray
        ]);
    }

    public function actionUploadTab($ajaxRequest = true) {
        $model = new $this->module->models['files'];
        $model->scenario = 'upload';
        $folderArray = [];

        $multiple = strtolower(Yii::$app->request->post('multiple')) === 'true' ? true : false;
        $maxFileCount = $multiple ? Yii::$app->request->post('maxFileCount') : 1;
        $folderId = Yii::$app->request->post('folderId');

        if (empty($folderId)) {
            $folderArray = ArrayHelper::map(Folders::find()->all(), 'folder_id', 'category');
        } else {
            $model->folder_id = $folderId;
        }
        $uploadView = $this->renderAjax('_file-input', [
            'model' => $model,
            'folderArray' => $folderArray,
            'uploadType' => Filemanager::TYPE_MODAL,
            'multiple' => $multiple,
            'maxFileCount' => $maxFileCount
        ]);

        if ($ajaxRequest) {
            echo $uploadView;
            \Yii::$app->end();
        }

        return $uploadView;
    }

    public function actionLibraryTab() {
        $searchModel = new $this->module->models['filesSearch'];
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $folderArray = ArrayHelper::merge(['' => Yii::t('filemanager', 'All')], ArrayHelper::map(Folders::find()->all(), 'folder_id', 'category'));

        if (Yii::$app->request->getQueryParam('page')) {
            echo Gallery::widget([
                'dataProvider' => $dataProvider,
                'viewFrom' => 'modal'
            ]);
            \Yii::$app->end();
        }

        echo $this->renderAjax('_search', [
            'model' => $searchModel,
            'folderArray' => $folderArray
        ]);
        echo $this->renderAjax('_grid-view', [
            'model' => $searchModel,
            'dataProvider' => $dataProvider,
            'uploadType' => Filemanager::TYPE_MODAL,
            'viewFrom' => 'modal'
        ]);
        \Yii::$app->end();
    }

    public function actionUse() {
        $fileId = Yii::$app->request->post('id');
        $model = $this->findModel($fileId);
        $fileType = $model->mime_type;
        if ($model->dimension) {
            $src = $model->object_url . $model->thumbnail_name;
            $fileType = 'image';
        } else {
            $src = $model->object_url . $model->src_file_name;
        }

        $toolArray = [
            ['tagType' => 'i', 'options' => ['class' => 'fa-icon fa fa-times fm-remove', 'title' => \Yii::t('filemanager', 'Remove')]]
        ];
        $gridBox = new \dpodium\filemanager\components\GridBox([
            'src' => $src,
            'fileType' => $fileType,
            'toolArray' => $toolArray,
            'thumbnailSize' => $this->module->thumbnailSize
        ]);
        $selectedFileView = $gridBox->renderGridBox();

        Yii::$app->response->format = Response::FORMAT_JSON;
        return ArrayHelper::merge($model->attributes, ['selectedFile' => $selectedFileView]);
    }

    /**
     * Finds the Files model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Files the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id) {
        $filesModel = $this->module->models['files'];
        if (($model = $filesModel::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }

    protected function saveModel($model, $extension) {
        $model->caption = $model->alt_text = str_replace($extension, '', $model->src_file_name);
        $tempFileName = $model->src_file_name;
        $model->src_file_name = str_replace($extension, '', $tempFileName);

        if ($model->validate()) {
            $model->src_file_name = str_replace(" ", "_", $model->src_file_name);
            $model->src_file_name = str_replace(["\"", "'"], "", $model->src_file_name) . $extension;

            if ($model->save()) {
                return true;
            }
        }

        $errors = [];
        foreach ($model->errors as $err) {
            $errors[] = $model->src_file_name . ': ' . $err[0];
        }
        echo Json::encode(['error' => implode('<br>', $errors)]);
        \Yii::$app->end();
    }

    protected function uploadToLocal($model, $file, $extension) {
        if (!file_exists(Yii::getAlias($model->storage_id) . $model->url)) {
            // File mode : 0755, Ref: http://php.net/manual/en/function.chmod.php
            mkdir(Yii::getAlias($model->storage_id) . $model->url, 0755, true);
        }

        if (!$file->saveAs(Yii::getAlias($model->storage_id) . $model->url . '/' . $model->src_file_name)) {
            $model->delete();
            echo Json::encode(['error' => Yii::t('filemanager', 'Upload fail due to some reasons.')]);
            \Yii::$app->end();
        }

        list($width, $height) = getimagesize(Yii::getAlias($model->storage_id) . $model->url . '/' . $model->src_file_name);
        $model->dimension = ($width && $height) ? $width . 'X' . $height : null;

        if ($model->dimension) {
            $thumbnailSize = $this->module->thumbnailSize;
            $model->thumbnail_name = 'thumb_' . str_replace($extension, '', $model->src_file_name) . '_' . $thumbnailSize[0] . 'X' . $thumbnailSize[1] . $extension;
            $this->createThumbnail($model);
            $model->update(false, ['dimension', 'thumbnail_name']);
        }

        return true;
    }

    protected function uploadToS3($model, $file, $extension) {
        $s3 = new S3();
        $result = $s3->upload($file, $model->src_file_name, trim($model->url));

        if (!$result['status']) {
            echo Json::encode(['error' => Yii::t('filemanager', 'Fail to create thumbnail.')]);
            \Yii::$app->end();
        }

        $model->object_url = str_replace($model->src_file_name, '', $result['objectUrl']);
        list($width, $height) = getimagesize($result['objectUrl']);
        $model->dimension = ($width && $height) ? $width . 'X' . $height : null;

        if ($model->dimension) {
            $thumbnailSize = $this->module->thumbnailSize;
            $model->thumbnail_name = 'thumb_' . str_replace($extension, '', $model->src_file_name) . '_' . $thumbnailSize[0] . 'X' . $thumbnailSize[1] . $extension;
            $this->createThumbnail($model);
        }
        $model->update(false, ['object_url', 'dimension', 'thumbnail_name']);

        return true;
    }

    protected function createThumbnail($model) {
        $thumbnailSize = $this->module->thumbnailSize;

        if (isset($this->module->storage['s3'])) {
            $thumbnailFile = Image::thumbnail($model->object_url . $model->src_file_name, $thumbnailSize[0], $thumbnailSize[1]);
            // create a temp physical file
            if (!file_exists('temp')) {
                mkdir('temp', 0777, true);
            }

            $thumbnailFile->save('temp/' . $model->thumbnail_name);
            $tempFile = new \stdClass();
            $tempFile->tempName = 'temp/' . $model->thumbnail_name;
            $tempFile->type = $model->mime_type;

            $s3 = new S3();
            $result = $s3->upload($tempFile, $model->thumbnail_name, $model->url);

            if (!$result['status']) {
                echo Json::encode(['error' => Yii::t('filemanager', 'Fail to create thumbnail.')]);
                \Yii::$app->end();
            }

            unlink($tempFile->tempName);
        } else {
            $thumbnailFile = Image::thumbnail(Yii::getAlias($model->storage_id) . $model->object_url . $model->src_file_name, $thumbnailSize[0], $thumbnailSize[1]);

            if (!file_exists(Yii::getAlias($model->storage_id) . $model->url)) {
                mkdir(Yii::getAlias($model->storage_id) . $model->url, 0755, true);
            }

            $result = $thumbnailFile->save(Yii::getAlias($model->storage_id) . $model->url . '/' . $model->thumbnail_name);

            if (!$result) {
                echo Json::encode(['error' => Yii::t('filemanager', 'Fail to create thumbnail.')]);
                \Yii::$app->end();
            }
        }

        return true;
    }

}
