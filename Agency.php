<?php

namespace app\models;

use app\models\base\AgencyCities;
use Yii;
use yii\data\ActiveDataProvider;
use yii\caching\TagDependency;
use yii\db\{ActiveQuery, Expression};
use app\helpers\UrlHelper;
use app\models\properties\PropertyInterface;
use app\components\behaviors\CachedBehavior;
use yii\helpers\{Inflector, ArrayHelper};

class Agency extends base\Agency implements PropertyInterface
{
    public $viewsCount;
    public $reViewsCount;
    public $sliderProfiles;
    public $profileCount;

    private const MAX_PROBABILITY = 50;
    public const PAGE_SIZE = 5;

    public static function nameInFilter(): string
    {
        return 'agency';
    }

    /**
     * properties that we turn to null if they are empty
     * @var array
     */
    private $nullable = ['phone', 'email', 'info',];

    /**
     * @return array
     */
    public function behaviors(): array
    {
        return ArrayHelper::merge(parent::behaviors(), [
            [
                'class' => CachedBehavior::class,
                'tags' => [self::tableName()],
            ]
        ]);
    }


    public function getLastAgencyTracking()
    {
        return $this->getAgencyTrackings()->orderBy('date')->one();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getPartner()
    {
        return $this->hasOne(Partners::className(), ['agency_id' => 'id']);
    }

    /**
     * @param bool $insert
     * @return bool
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            if ($insert === true && empty($this->created_at)) {
                $this->created_at = time();
                $this->updated_at = $this->created_at;
            } elseif ($insert !== true) {
                $this->updated_at = time();
            }

            $this->name = trim($this->name);

            foreach ($this->nullable as $property) {
                if (empty($this->{$property})) {
                    $this->{$property} = null;
                }
            }

            if (empty($this->agency_page)) {
                $this->agency_page = Inflector::slug(empty($this->agency_page) ? $this->name : $this->agency_page);
            }

            return true;
        }

        return false;
    }

    /**
     * remove joined instances
     * @return boolean
     */
    public function beforeDelete(): bool
    {
        if (!empty($this->logo_path)) {
            \Yii::setAlias('@images', '@webroot/images');
            $fileName = \Yii::getAlias($this->logo_path);
            if (file_exists($fileName)) {
                \Yii::info(sprintf('Trying to delete file "%s"', $fileName), __METHOD__);
                unlink($fileName);
            }
        }

        ProfileView::deleteAll(['agency_id' => $this->id]);
        foreach (Profile::findAll(['agency_id' => $this->id]) as $profile) {
            $profile->delete();
        }

        return parent::beforeDelete();
    }

    /**
     * @return array
     */
    public function safeAttributes(): array
    {
        return [
            'name',
            'phone',
            'website',
            'email',
            'viewsCount',
            'countryName',
            'cityName',
            'agency_page',
            'banner_snapshot',
            'visible',
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'profilesCount' => 'stats',
            'cityName' => 'City',
            'countryName' => 'Country',
        ]);
    }

    /**
     * @return string
     */
    public function getCitiesNames(): string
    {
        $cities = $this->agencyCities;
        $result = [];
        foreach ($cities as $city) {
            $result[] = $city->city->name;
        }
        return implode(', ', $result);
    }

    /**
     * @return int
     */
    public function getBrokenProfilesCount($countryId = null, $cityId = null): int
    {
        $profiles = $this->getProfiles()
            ->where(['is_broken' => 1])
            ->andWhere(['is_archived' => 0]);
        $profiles->joinWith(['cities', 'cities.countries']);

        if ($countryId) {
            $profiles->andWhere(['countries.id' => $countryId]);
        }

        if ($cityId) {
            $profiles->andWhere(['cities.id' => $cityId]);
        }

        return $profiles->count();
    }

    /**
     * @return int
     */
    public function getArchivedProfilesCount($countryId = null, $cityId = null): int
    {
        $profiles = $this->getProfiles()
            ->where(['is_broken' => 0])
            ->andWhere(['is_archived' => 1]);
        $profiles->joinWith(['cities', 'cities.countries']);

        if ($countryId) {
            $profiles->andWhere(['countries.id' => $countryId]);
        }

        if ($cityId) {
            $profiles->andWhere(['cities.id' => $cityId]);
        }

        return $profiles->count();
    }

    /**
     * count girls
     * @param integer|string $country
     * @param integer|string $city
     * @return integer
     */
    public function getActiveProfilesCount($country = null, $city = null): int
    {
        $profiles = $this->getProfiles()
            ->where([
                'is_broken' => 0,
                'is_archived' => 0,
                'gender' => Profile::DEFAULT_GENDER,
            ]);
        if ($country || $city) {
            $profiles->joinWith(['cities', 'cities.countries']);
        }

        if ($country) {
            if (is_numeric($country)) {
                $profiles->andWhere(['countries.id' => $country]);
            } else {//string $country
                $profiles->andWhere(['countries.code' => $country]);
            }
        }

        if ($city) {
            if (is_numeric($city)) {
                $profiles->andWhere(['cities.id' => $city]);
            } else {//string $city
                $profiles->andWhere(['cities.name' => $city]);
            }
        }

        return $profiles->count();
    }

    /**
     * update redis
     * @param string $name
     * @param int $count
     */
    public function setProfilesCount()
    {
        foreach ($this->getAllProfileCount() as $key => $count) {
            if (empty(Yii::$app->redis->get($key))) {
                Yii::$app->redis->set($key, json_encode([$this->id => $count]));
            } else {
                $countProfiles = json_decode(Yii::$app->redis->get($key), true);
                $countProfiles[$this->id] = $count;
                Yii::$app->redis->set($key, json_encode($countProfiles));
            }
        }
    }

    /**
     * @return mixed
     */
    public function getAllProfileCount()
    {
        $profiles = $this->getProfiles();
        $profile['countActiveProfiles'] = $profiles->where(['is_broken' => 0])->andWhere(['is_archived' => 0])->count() ?? 0;
        $profile['countArchivedProfiles'] = $profiles->where(['is_archived' => 1])->count() ?? 0;
        $profile['countBrokenProfiles'] = $profiles->where(['is_broken' => 1])->count() ?? 0;

        return $profile;
    }

    public function getLogo()
    {
        return $this->logo_path ? \Yii::getAlias($this->logo_path) : '/img/v2/agency-accent-colr.png';
    }

    /**
     * @return int|string
     */
    public function getProfilesCount()
    {
        $countActiveProfiles = Yii::$app->redis->get('countActiveProfiles');

        if (!empty($countActiveProfiles) && !empty(json_decode($countActiveProfiles, true)[$this->name])) {
            return json_decode($countActiveProfiles, true)[$this->name];
        }

        return Profile::find()
            ->where(['agency_id' => $this->id])
            ->andWhere(['is_broken' => 0])
            ->andWhere(['is_archived' => 0])
            ->andWhere(['gender' => Profile::DEFAULT_GENDER])
            ->count();
    }

    /**
     * @param $params
     * @param int $pageSize
     * @return ActiveDataProvider
     */
    public function search($params, $pageSize = self::PAGE_SIZE)
    {
        $query = self::find()
            ->select(['agency.*', '(SELECT count(*) FROM ' . ProfileView::tableName() . ' WHERE agency_id = `agency`.`id`) as viewsCount'])
            ->where(['visible' => 1])
            ->andWhere('(SELECT count(*) FROM `profiles` 
                WHERE `profiles`.`agency_id` = `agency`.`id` 
                AND is_broken = 0 AND is_archived = 0) > 0'
            );
        $query->joinWith(['agencyCities.city.countries']);
        $query->distinct();

        if (!empty($params['sort'])) {
            if ($params['sort'] === 'name') {
                $query->orderBy(['name' => SORT_ASC]);
            } elseif ($params['sort'] === 'popular') {
                //$query->where('profile_view.view_date >= CURDATE() - 30');
                $query->orderBy(['viewsCount' => SORT_DESC]);
            } elseif ($params['sort'] === 'web_verified') {
                $query->orderBy(['web_verified' => SORT_DESC, 'weight' => SORT_DESC]);
            }
        } else {
            $query->orderBy(['web_verified' => SORT_DESC, 'weight' => SORT_DESC]);
        }

        $query = $this->setQueryFilters($query, $params);


        $paramsPagination['pageSize'] = $pageSize;

        if (!empty($params['page'])) {
            $paramsPagination['page'] = $params['page'];

        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => $paramsPagination,
        ]);

        return $dataProvider;
    }

    /**
     * @param ActiveQuery $query
     * @param array $params
     * @return ActiveQuery
     */
    public function setQueryFilters(ActiveQuery $query, array $params): ActiveQuery
    {
        $query->andFilterWhere($this->getAgencyByQueryString($params));
        $query->andFilterWhere($this->ifAgencyIsApplicant($params));
        $query->andFilterWhere($this->getAgencyByCountry($params));
        $query->andFilterWhere($this->getAgencyByCity($params));
        $query->andFilterWhere($this->getAgencyByCityId($params));
        $query->andFilterWhere($this->getAgencyByWebsiteFilter($params));
        $query->andFilterWhere($this->getAgencyByEmail($params));
        $query->andFilterWhere($this->getAgencyByApproved($params));
        $query->andFilterWhere($this->getAgencyByIsPromoFilter($params));
        $query->andFilterWhere($this->getAgencyByNameFilter($params));
        $query->andFilterWhere($this->getAgencyByVisibleFilter($params));
        $query->andFilterWhere($this->getAgencyByIsPartnerFilter($params));

        return $query;
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByVisibleFilter($params)
    {
        if (isset($params['visible'])) {
            return ['=', 'visible', $params['visible']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByIsPartnerFilter($params)
    {
        if (isset($params['is_partner'])) {
            return ['=', 'is_partner', $params['is_partner']];
        }

        return [];
    }


    /**
     * @param $params
     * @return array
     */
    private function ifAgencyIsApplicant($params): array
    {
        if (isset($params['applicant']) && $params['applicant'] == 1) {
            return ['applicant' => 1];
        }
        return [];
    }

    /**
     * @param $params
     * @return array
     */
    private function getAgencyByNameFilter($params): array
    {
        if (!empty($params[0]) && !empty($params[0]['name'])) {
            return ['OR',
                ['LIKE', 'agency.name', $params[0]['name']],
                ['LIKE', 'agency.website', $params[0]['name']]
            ];
        }

        //Search in admin
        if (!empty($params['name'])) {
            return ['OR',
                ['LIKE', 'agency.name', $params['name']],
                ['LIKE', 'agency.website', $params['name']]
            ];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByIsPromoFilter($params): array
    {

        if (isset($params['is_promo'])) {
            return ['!=', 'is_promo', $params['is_promo']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByApplicant($params): array
    {
        if (isset($params['applicant'])) {
            return ['=', 'applicant', $params['applicant']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByApproved($params): array
    {
        if (isset($params['approved'])) {
            return ['=', 'approved', $params['approved']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByWebsiteFilter($params): array
    {
        if (!empty($params['website'])) {


            return ['LIKE', 'website', $params['website']];
        }

        if (!empty($params[0]['AgencySearch']['website'])) {
            return ['LIKE', 'website', $params[0]['AgencySearch']['website']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByEmail($params): array
    {
        if (!empty($params['AgencySearch']['email'])) {
            return ['LIKE', 'email', $params['AgencySearch']['email']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByCountry($params): array
    {
        if (isset($params['country'])) {
            return ['=', 'countries.code', $params['country']];
        }

        if (isset($params['countryId'])) {
            return ['=', 'countries.id', $params['countryId']];
        }

        if (!empty($params[0]['AgencySearch']['countryName'])) {

            return ['LIKE', 'countries.name', $params[0]['AgencySearch']['countryName']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByCity($params): array
    {

        if (isset($params['city']) && !isset($params['cityId'])) {

            return ['=', 'cities.name', $params['city']];
        }

        if (isset($params[0]['AgencySearch']['cityName'])) {
            return ['=', 'cities.name', $params[0]['AgencySearch']['cityName']];
        }

        return [];
    }

    /**
     * @param $params
     * @return array
     */
    public function getAgencyByCityId($params): array
    {
        if (isset($params['cityId']) && (int)$params['cityId'] !== 0) {
            return ['=', 'cities.id', $params['cityId']];
        }

        return [];
    }


    /**
     * @param $params
     * @return array
     */
    private function getAgencyByApplicants($params): array
    {

        if (isset($params['applicant'])) {
            return ['=', 'applicant', $params['applicant']];
        }

        return [];
    }

    /**
     * @param array $params
     * @return array
     */
    private function getAgencyByQueryString(array $params): array
    {
        if (!empty($params['agencyName'])) {
            return ['LIKE', 'agency.name', $params['agencyName']];
        }

        return [];
    }


    /**
     * @param string|null $name
     * @return array|null|\yii\db\ActiveRecord
     */
    public function getActiveAgencyByName(string $name = null)
    {
        if (null === $name) {
            return null;
        }
        $name = str_replace('-', ' ', $name);

        return self::find()
            ->where(['=', 'REPLACE(name, "-", " ")', $name])
            ->andWhere(['visible' => true])
            ->one();
    }

    public function isLastPage(ActiveDataProvider $dataProvider, $pageSize = 3, $currentPage = 1)
    {
        $totalAgencies = $dataProvider->getTotalCount();
        $totalPages = ceil($totalAgencies / $pageSize);

        return $currentPage < $totalPages ? false : true;
    }

    public static function countAll()
    {
        return self::find()
            ->where(['applicant' => 0])
            ->count();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getProfiles()
    {
        return $this->hasMany(Profile::className(), ['agency_id' => 'id']);
    }

    /**
     * for view
     * @param string $country two letters of code
     * @param string $city full name
     * @return array
     */
    public function getSliderProfiles(string $country = null, string $city = null): array
    {
        $profile = Profile::find()
            ->where(['agency_id' => $this->id])
            ->andWhere(['is_broken' => 0])
            ->andWhere(['is_archived' => 0])
            ->andWhere(['gender' => Profile::DEFAULT_GENDER])
            ->orderBy(new Expression('rand()'))
            ->limit(8);
        $profile->joinWith(['cities', 'cities.countries']);
        if ($country) {
            $profile->andWhere(['countries.code' => $country]);
        }
        if ($city) {
            $profile->andWhere(['cities.name' => $city]);
        }

        return $profile->all();
    }

    public function getCountProfiles()
    {
        return $this->getProfiles()
            ->where(['is_broken' => 0])
            ->andWhere(['is_archived' => 0])
            ->andWhere(['gender' => Profile::DEFAULT_GENDER])
            ->count();
    }

    /**This function returns the array of not visible agencies ids [visible => 0]
     * @return array
     */
    public static function getNotVisible()
    {
        $agencies = self::find()->select('id')->where(['visible' => 0])->all();
        $result = [];
        foreach ($agencies as $record) {
            $result[] = $record->id;
        }

        return $result;
    }

    /**
     * @param int $id
     * @return array|null|\yii\db\ActiveRecord
     */
    public function getAgencyById(int $id)
    {
        return self::find()
            ->where(['=', 'id', $id])
            ->one();
    }

    /**
     * Ads 'visible' in 'where' scope
     *
     * @param bool $state
     * @return ActiveQuery
     */
    public static function visible($state = true)
    {
        return self::find()->andWhere(['visible' => $state]);
    }

    /**
     * @param string $name
     * @param int $id
     * @return array|null|\yii\db\ActiveRecord
     */
    public function getNewAgencyByName(string $name, int $id)
    {
        return self::find()
            ->where(['=', 'name', $name])
            ->andWhere(['!=', 'id', $id])
            ->one();
    }

    public function getAgencyByAttributeExceptCurrent(string $attribute, string $value, int $id)
    {
        return self::find()
            ->where([$attribute => $value])
            ->andWhere(['!=', 'id', $id])
            ->one();
    }

    /**
     * @return string
     */
    public function getUrlForAgency(): string
    {
        $countryCode = $this->city->countries->code ?? Countries::getDefault()->code;

        $cityName = !empty($this->city->name) ? $this->city->name : 'london';
        $cityName = UrlHelper::prepareLink($cityName);

        $agencyName = !empty($this->name) ? $this->name : 'independent';
        $agencyName = UrlHelper::prepareLink($agencyName);

        return '/agency/' . strtolower($countryCode) . "/$cityName/$agencyName";
    }

    /**
     * @return array|\yii\db\ActiveRecord[]
     */
    public function getAllAgencies()
    {
        return self::find()
            ->orderBy('name')
            ->all();
    }

    /**
     * @param string $website
     * @return array|null|\yii\db\ActiveRecord
     */
    public function getAgencyBySite(string $website)
    {
        return self::find()
            ->where(['website' => $website])
            ->one();
    }

    /**
     * @return int
     */
    public static function getApplicantAgenciesCount(): int
    {
        return self::find()
            ->where(['applicant' => 1])
            ->andWhere(['approved' => 1])
            ->count();
    }

    /**
     * @return int
     */
    public static function getIndependentApplicantAgenciesCount(): int
    {
        return self::find()
            ->where(['applicant' => 1])
            ->andWhere(['approved' => 0])
            ->count();
    }

    /**
     * @param string $website
     * @return array|null|\yii\db\ActiveRecord
     */
    public function getAgencyByWebsite(string $website)
    {
        return self::find()
            ->where(['LIKE', 'website', $website])
            ->one();
    }


    /**
     * @param $agencyData
     * @return bool
     */
    public function createAgencyByData($agencyData): bool
    {
        foreach ($agencyData as $key => $val) {
            $this->$key = $val;
        }

        return $this->save();
    }

    /**
     * @param $promotion
     * @return array|\yii\db\ActiveRecord[]
     */
    public function getAgencyByPromotion($promotion): array
    {
        return self::find()->where(['is_promo' => $promotion])->all();
    }

    /**
     * @param string $website
     * @return Agency|array|null|\yii\db\ActiveRecord
     */
    public static function getAgencyByWebsiteDisregardHost(string $website)
    {
        $site = str_replace(['http://', 'https://', 'www.', '/', '\''], '', $website);

        return self::find()->where(['LIKE', 'REPLACE(website, "/", " ")', '%' . $site . '%', false])->one();
    }

    /**
     * @param $agencies
     * @return array
     */
    public static function countReferralsByAgencies($agencies): array
    {
        $agenciesReferrals = [];
        if (null === $agencies || empty($agencies['rows'])) {
            return $agenciesReferrals;
        }

        foreach ($agencies['rows'] as $agency) {

            if (preg_match('/[a-z0-9\.-]+/i', $agency[2], $agencyName)) {
                $agencyName = $agencyName[0];
                $newVisitor = $agency[3] ?? 0;
            }
            if (empty($agenciesReferrals['\'' . $agencyName . '\''])) {
                $agenciesReferrals['\'' . $agencyName . '\''] = 0;
            }

            $agenciesReferrals['\'' . $agencyName . '\''] += $newVisitor;
        }

        return $agenciesReferrals;
    }


    /**
     * @param $agenciesReferrals
     * @return array
     */
    public static function changeProbabilityByPartnerReferral($agenciesReferrals): array
    {
        $statuses = [];
        $countAllReferrals = array_sum($agenciesReferrals);

        $countReferrals = [];
        foreach ($agenciesReferrals as $agencyName => $countReferral) {
            $agencyBySite = self::getAgencyByWebsiteDisregardHost($agencyName);
            if (!empty($agencyBySite)) {
                $countReferrals[] = $countReferral;
            }
        }

        $max = max($countReferrals);

        foreach ($agenciesReferrals as $agencyName => $countReferral) {
            $agencyBySite = self::getAgencyByWebsiteDisregardHost($agencyName);
            if (!empty($agencyBySite)) {
                $agencyBySite->probability = ceil(self::MAX_PROBABILITY / ($max / $countReferral));
                $agencyBySite->is_promo = 1;
                if ($agencyBySite->save()) {
                    $statuses['success'][] = $agencyName . 'countReferral ' . $countReferral . ' probability ' . $agencyBySite->probability;
                } else {
                    $statuses['error'][] = 'Error set probability ' . $agencyName;
                }
            }
        }

        return $statuses;
    }

    /**
     * @return mixed
     * @throws \Throwable
     */
    public static function getAllPublishedAgencyFromFilterPanel()
    {
        return self::getDb()->cache(function ($db) {
            return self::find()
                    ->where(['visible' => true])
                    ->orderBy(['name' => SORT_ASC])
                    ->all() ?? [];
        }, 0, new TagDependency(['tags' => self::tableName()]));
    }

    /**
     * @return mixed
     */
    public function getAgencyMinimalPrice()
    {
        return Profile::find()
            ->where(['=', 'agency_id', $this->id])
            ->where(['!=', 'price_hour_incall', ''])
            ->where(['>', 'price_hour_incall', 10])
            ->orderBy(['price_hour_incall' => SORT_ASC])
            ->limit(1)
            ->one()
            ->price_hour_incall;
    }

    /**
     * @return bool
     * @deprecated unused
     */
    public function setCity(int $cityId)
    {
        if (!empty($this->city_id) && !AgencyCity::isCityExistsInAgency($this->id, $cityId)) {
            $agencyCity = new AgencyCity();
            $agencyCity->agency_id = $this->id;
            $agencyCity->city_id = $cityId;
            if (!$agencyCity->save()) {
                return false;
            }
        }
        return true;
    }

    /**
     * for HTML selects
     * @return ID => name of property
     * @throws \Throwable
     */
    public static function getModerated(): array
    {
        $moderated = self::getDb()->cache(function ($db) {
            return self::find()
                ->where(['visible' => true])
                ->orderBy(['name' => SORT_ASC])
                ->all();
        }, 0, new \yii\caching\TagDependency(['tags' => self::tableName()]));
        foreach ($moderated as $property) {
            $properties[$property->id] = $property->name;
        }
        return $properties ?? [];
    }

    /**
     * for HTML selects
     * @param array $independent
     * @param null|int $city_id
     * @param null|int $country_id
     * @return array
     */
    public static function getForSelect($independent = [], $city_id = null, $country_id = null): array
    {
        $query = self::find()->select(['agency.id', 'agency.name']);
//        $query->andWhere('agency.visible = true');

        if (!empty($country_id)) {
            $query->leftJoin('agency_cities', 'agency_cities.agency_id = agency.id');
            if (!empty($city_id)) {
                $query->andWhere(['=', 'agency_cities.city_id', $city_id]);
            } else {
                $query->leftJoin('cities', 'agency_cities.city_id = cities.id');
                $query->andWhere(['=', 'cities.countries_id', $country_id]);
            }
        }

        $objects = $query->orderBy(['name' => SORT_ASC])->all();

        if (!empty($independent)) {
            $array = $independent;
        }

        foreach ($objects as $agency) {
            $array[$agency->id] = $agency->name;
        }
        return $array ?? [];
    }

    public function getPrevious($city_id)
    {
        $subquery = (new \yii\db\Query)->select('agency_id')->from('agency_cities')->where(['city_id' => $city_id]);
        $query = self::find()
            ->where(['visible' => true])
            ->andFilterWhere(['<=', 'created_at', $this->created_at])
            ->andFilterWhere(['or',
                ['id' => $subquery],
                ['id' => $city_id]
            ])
            ->orderBy('created_at DESC, name')
            ->limit(2);
        $result = $query->all();
        if (count($result) == 2) return $result[1];
        return null;
    }

    public function getNext($city_id)
    {
        $subquery = (new \yii\db\Query)->select('agency_id')->from('agency_cities')->where(['city_id' => $city_id]);
        $query = self::find()
            ->where(['visible' => true])
            ->andFilterWhere(['>=', 'created_at', $this->created_at])
            ->andFilterWhere(['or',
                ['id' => $subquery],
                ['id' => $city_id]
            ])
            ->orderBy('created_at ASC, name')
            ->limit(2);
        $result = $query->all();
        if (count($result) == 2) return $result[1];
        return null;
    }

    public function getText(): ActiveQuery
    {
        return $this->hasOne(Text::class, ['agency_id' => 'id']);
    }

    public function setCitiesList(): void
    {
        $agencyCities = Profile::find()
            ->select('city_id, agency_id')->where(['is_broken' => 0,  'is_archived' => 0] )
            ->andWhere('agency_id is not null and city_id is not null')
            ->andWhere(['agency_id' => $this->id])
            ->groupBy('city_id, agency_id')
            ->all();
        (new AgencyCity())->deleteAll(['agency_id' => $this->id]);

        foreach ($agencyCities as $ac) {
            if ($ac->agency->visible && $ac->city->published && $ac->city->country->published) {
                (new AgencyCities(['city_id' => $ac->city_id,  'agency_id' => $ac->agency_id]))->save();
            }
        }

    }

}
