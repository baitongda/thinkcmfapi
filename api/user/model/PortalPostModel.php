<?php

namespace api\user\model;
use think\Db;
use api\common\model\CommonModel;

class PortalPostModel extends CommonModel
{
	//可查询字段
	protected $visible = [
		'id', 'articles.id', 'user_id', 'post_id', 'post_type', 'comment_status',
		'is_top', 'recommended', 'post_hits', 'post_like', 'comment_count',
		'create_time', 'update_time', 'published_time', 'post_title', 'post_keywords',
		'post_excerpt', 'post_source', 'post_content', 'more', 'user_nickname',
		'user', 'category_id'
	];
	//类型转换
	protected $type = [
		'more' => 'array',
	];

	//设置只读字段
	protected $readonly = ['user_id'];

	/**
	 * post_content 自动转化
	 * @param $value
	 * @return string
	 */
	public function getPostContentAttr($value)
	{
		return cmf_replace_content_file_url(htmlspecialchars_decode($value));
	}

	/**
	 * post_content 自动转化
	 * @param $value
	 * @return string
	 */
	public function setPostContentAttr($value)
	{
		return htmlspecialchars(cmf_replace_content_file_url(htmlspecialchars_decode($value), true));
	}

	/**
	 * 基础查询
	 */
	protected function base($query)
	{
		$query->where('delete_time', 0)
			->where('post_status', 1)
			->whereTime('published_time', 'between', [1, time()]);
	}

	/**
	 * more 自动转化
	 * @param $value
	 * @return array
	 */
	public function getMoreAttr($value)
	{
		$more = json_decode($value, true);
		if (!empty($more['thumbnail'])) {
			$more['thumbnail'] = cmf_get_image_url($more['thumbnail']);
		}

		if (!empty($more['photos'])) {
			foreach ($more['photos'] as $key => $value) {
				$more['photos'][$key]['url'] = cmf_get_image_url($value['url']);
			}
		}

		if (!empty($more['files'])) {
			foreach ($more['files'] as $key => $value) {
				$more['files'][$key]['url'] = cmf_get_image_url($value['url']);
			}
		}
		return $more;
	}

	/**
	 * 关联分类表
	 * @return $this
	 */
	public function categories()
	{
		return $this->belongsToMany('PortalCategoryModel', 'portal_category_post', 'category_id', 'post_id');
	}

	/**
	 * 关联标签表
	 * @return $this
	 */
	public function tags()
	{
		return $this->belongsToMany('PortalTagModel', 'portal_tag_post', 'tag_id', 'post_id');
	}

	/**
	 * 关联 user表
	 * @return $this
	 */
	public function user()
	{
		return $this->belongsTo('UserModel', 'user_id');
	}

	/**
	 * 获取用户文章
	 */
	public function getUserArticles($userId,$params)
	{
		$where  =   [
			'post_type'     =>  1,
			'user_id'          =>  $userId
		];
		if (!empty($params)) {
			$this->paramsFilter($params);
		}
		return $this->where($where)->select();
	}

	/**
	 * 会员添加文章
	 * @param array $data 文章数据
	 * @return $this
	 */
	public function addArticle($data)
	{
		if (!empty($data['annex'])) {
			$data['more'] = $this->setAnnexUrl($data['annex']);
		}
		if (!empty($data['thumbnail'])) {
			$data['more']['thumbnail'] = cmf_asset_relative_url($data['thumbnail']);
		}
		$this->allowField(true)->data($data, true)->isUpdate(false)->save();
		$categories = $this->strToArr($data['categories']);
		$this->categories()->attach($categories);
		if (!empty($data['post_keywords']) && is_string($data['post_keywords'])) {
			//加入标签
			$data['post_keywords'] = str_replace('，', ',', $data['post_keywords']);
			$keywords = explode(',', $data['post_keywords']);
			$this->addTags($keywords, $this->id);
		}
		return $this;
	}

	/**
	 * 会员文章修改
	 * @param array $data 文章数据
	 * @param int   $id     文章id
	 * @return boolean   成功 true 失败 false
	 */
	public function editArticle($data,$id,$userId = '')
	{
		if (!empty($userId)) {
			$isBelong = $this->isuserPost($id,$userId);
			if ($isBelong === false) {
				return $isBelong;
			}
		}
		if (!empty($data['annex'])) {
			$data['more'] = $this->setAnnexUrl($data['annex']);
		}
		if (!empty($data['thumbnail'])) {
			$data['more']['thumbnail'] = cmf_asset_relative_url($data['thumbnail']);
		}
		$data['id']          =  $id;
		$data['post_status'] = empty($data['post_status']) ? 0 : 1;
		$data['is_top']      = empty($data['is_top']) ? 0 : 1;
		$data['recommended'] = empty($data['recommended']) ? 0 : 1;
		$this->allowField(true)->data($data, true)->isUpdate(true)->save();

		$categories = $this->strToArr($data['categories']);
		$oldCategoryIds        = $this->categories()->column('category_id');
		$sameCategoryIds       = array_intersect($categories, $oldCategoryIds);
		$needDeleteCategoryIds = array_diff($oldCategoryIds, $sameCategoryIds);
		$newCategoryIds        = array_diff($categories, $sameCategoryIds);
		if (!empty($needDeleteCategoryIds)) {
			$this->categories()->detach($needDeleteCategoryIds);
		}
		if (!empty($newCategoryIds)) {
			$this->categories()->attach(array_values($newCategoryIds));
		}
		if (is_string($data['post_keywords'])) {
			//加入标签
			$data['post_keywords'] = str_replace('，', ',', $data['post_keywords']);
			$keywords = explode(',', $data['post_keywords']);
		}
		$this->addTags($keywords, $data['id']);
		return $this;
	}

	/**
	 * 根据文章关键字，增加标签
	 * @param array $keywords     文章关键字数组
	 * @param int $articleId    文章id
	 * @return void
	 */
	public function addTags($keywords,$articleId)
	{
		foreach ($keywords as $key=>$value) {
			$keywords[$key] = trim($value);
		}
		$continue = true;
		$names = $this->tags()->column('name');
		if (!empty($keywords) || !empty($names)) {
			if (!empty($names)) {
				$sameNames              =   array_intersect($keywords, $names);
				$keywords               =   array_diff($keywords, $sameNames);
				$shouldDeleteNames      =   array_diff($names, $sameNames);
				if (!empty($shouldDeleteNames)) {
					$tagIdNames         =   $this->tags()
													->where('name','in',$shouldDeleteNames)
													->column('pivot.id','tag_id');
					$tagIds             =   array_keys($tagIdNames);
					$tagPostIds         =   array_values($tagIdNames);
					$tagPosts           =   DB::name('portal_tag_post')->where('tag_id','in',$tagIds)
																			->field('id,tag_id,post_id')
																			->select();
					$keepTagIds = [];
					foreach ($tagPosts as $key=>$tagPost) {
						if ($articleId  != $tagPost['post_id']) {
							array_push($keepTagIds,$tagPost['tag_id']);
						}
					}
					$keepTagIds         = array_unique($keepTagIds);
					$shouldDeleteTagIds = array_diff($tagIds,$keepTagIds);
					DB::name('PortalTag')->delete($shouldDeleteTagIds);
					DB::name('PortalTagPost')->delete($tagPostIds);
				}
			} else {
				$tagIdNames = DB::name('portal_tag')->where('name','in',$keywords)->column('name','id');
				if (!empty($tagIdNames)) {
					$tagIds     =   array_keys($tagIdNames);
					$this->tags()->attach($tagIds);
					$keywords   =   array_diff($keywords,array_values($tagIdNames));
					if (empty($keywords)) {
						$continue   =   false;
					}
				}
			}
			if ($continue) {
				foreach ($keywords as $key=>$value) {
					if (!empty($value)) {
						$this->tags()->attach(['name' => $value]);
					}
				}
			}
		}
	}

	/**
	 * 获取图片附件url相对地址
	 * 默认上传名字 *_names  地址 *_urls
	 * @param $annex 上传附件
	 * @return array
	 */
	public function setAnnexUrl($annex)
	{
		$more = [];
		if (!empty($annex)) {
			foreach ($annex as $key=>$value) {
				$nameArr  =   $key . '_names';
				$urlArr   =   $key . '_urls';
				if (is_string($value[$nameArr]) && is_string($value[$urlArr])) {
					$more[$key] = [ $value[$nameArr] ,$value[$urlArr] ];
				} elseif (!empty($value[$nameArr]) && !empty($value[$urlArr])) {
					$more[$key] = [];
					foreach ($value[$urlArr] as $k=>$url) {
						$url = cmf_asset_relative_url($url);
						array_push( $more[$key] , ['url' => $url , 'name' => $value[$nameArr][$k]] );
					}
				}
			}
		}
		return $more;
	}

	/**
	 * 删除文章
	 * @param $ids  int|array   文章id
	 * @param $userId   当前用户id
	 * @return bool|int 删除结果
	 */
	public function deleteArticle($ids,$userId)
	{
		$time   = time();
		$result = false;
		$where = [];
		if (!empty($userId)) {
			if (is_numeric($ids)) {
				if ($this->isUserPost($ids,$userId) || $userId == 1) {
					$where['id']    =   $ids;
				}
			} elseif (is_string($ids)) {
				$ids        =   explode(',',$ids);
				$deleteIds  =   $this->isUserPosts($ids,$userId);
				if (!empty($deleteIds)) {
					$where['id']    =   [ 'in' , $deleteIds ];
				}
			}
		} else {
			if (is_numeric($ids)) {
				$where['id'] = $ids;
			}
			if (is_string($ids)) {
				$where['id'] = [ 'in' , $ids ];
			}
		}
		if (!empty($where)) {
			$result = $this->useGlobalScope(false)
						   ->where($where)
						   ->setField('delete_time',$time);
		}
		return $result;
	}

	/**
	 * 判断文章所属用户是否为当前用户，超级管理员除外
	 * @params  int $id     文章id
	 * @param   int $userId     当前用户id
	 * @return  boolean     是 true , 否 false
	 */
	public function isUserPost($id,$userId)
	{
		$postUserId = $this->useGlobalScope(false)
						   ->getFieldById($id,'user_id');
		if ($postUserId != $userId || $userId != 1) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * 过滤属于当前用户的文章，超级管理员除外
	 * @params  array $ids     文章id的数组
	 * @param   int $userId     当前用户id
	 * @return  array     属于当前用户的文章id
	 */
	public function isUserPosts($ids,$userId)
	{
		$postIds   =   $this->useGlobalScope(false)
						    ->where('user_id',$userId)
						    ->where('id','in',$ids)
						    ->column('id');
		return array_intersect($ids,$postIds);
	}
}
