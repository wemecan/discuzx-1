<?php

/**
 *      [Discuz!] (C)2001-2099 Comsenz Inc.
 *      This is NOT a freeware, use is subject to license terms
 *
 *      $Id: helper_notification.php 34003 2013-09-18 04:31:14Z nemohou $
 */

if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

class helper_notification {


	/**
	 * 通知
	 * @param Integer $touid: 通知给谁
	 * @param String $type: 通知类型
	 * @param String $note: 语言key
	 * @param Array $notevars: 语言变量对应的值
	 * @param Integer $system: 是否为系统通知 0:非系统通知; 1:系统通知
	 */
	public static function notification_add($touid, $type, $note, $notevars = array(), $system = 0, $category = -1) {
		global $_G;

		//已经存档用户将不再接收通知
		if(!($tospace = getuserbyuid($touid))) {
			return false;
		}
		space_merge($tospace, 'field_home');
		$filter = empty($tospace['privacy']['filter_note'])?array():array_keys($tospace['privacy']['filter_note']);

		//检查用户屏蔽
		if($filter && (in_array($type.'|0', $filter) || in_array($type.'|'.$_G['uid'], $filter))) {
			return false;
		}
		if($category == -1) {
			$category = 0;
			$categoryname = '';
			if($type == 'follow' || $type == 'follower') {
				switch ($type) {
							case 'follow' : $category = 5; break;
							case 'follower' : $category = 6; break;
						}
				$categoryname = $type;
			} else {
				foreach($_G['notice_structure'] as $key => $val) {
					if(in_array($type, $val)) {
						switch ($key) {
							case 'mypost' : $category = 1; break;
							case 'interactive' : $category = 2; break;
							case 'system' : $category = 3; break;
							case 'manage' : $category = 4; break;
							default :  $category = 0;
						}
						$categoryname = $key;
						break;
					}
				}
			}
		} else {
			switch ($category) {
				case 1 : $categoryname = 'mypost'; break;
				case 2 : $categoryname = 'interactive'; break;
				case 3 : $categoryname = 'system'; break;
				case 4 : $categoryname = 'manage'; break;
				case 5 : $categoryname = 'follow'; break;
				case 6 : $categoryname = 'follower'; break;
				default :  $categoryname = 'app';
			}
		}
		if($category == 0) {
			$categoryname = 'app';
		} elseif($category == 1 || $category == 2) {
			$categoryname = $type;
		}
		$notevars['actor'] = "<a href=\"home.php?mod=space&uid=$_G[uid]\">".$_G['member']['username']."</a>";
		//非漫游通知
		if(!is_numeric($type)) {
			$vars = explode(':', $note);
			if(count($vars) == 2) {
				$notestring = lang('plugin/'.$vars[0], $vars[1], $notevars);
			} else {
				$notestring = lang('notification', $note, $notevars);
			}
			$frommyapp = false;
		} else {
			$frommyapp = true;
			$notestring = $note;
		}

		//去重
		$oldnote = array();
		if($notevars['from_id'] && $notevars['from_idtype']) {
			$oldnote = C::t('home_notification')->fetch_by_fromid_uid($notevars['from_id'], $notevars['from_idtype'], $touid);
		}
		if(empty($oldnote['from_num'])) $oldnote['from_num'] = 0;
		$notevars['from_num'] = $notevars['from_num'] ? $notevars['from_num'] : 1;
		$setarr = array(
			'uid' => $touid,
			'type' => $type,
			'new' => 1,
			'authorid' => $_G['uid'],
			'author' => $_G['username'],
			'note' => $notestring,
			'dateline' => $_G['timestamp'],
			'from_id' => $notevars['from_id'],
			'from_idtype' => $notevars['from_idtype'],
			'from_num' => ($oldnote['from_num']+$notevars['from_num']),
			'category' => $category
		);
		if($system) {
			$setarr['authorid'] = 0;
			$setarr['author'] = '';
		}
		$pkId = 0;
		if($oldnote['id']) {
			C::t('home_notification')->update($oldnote['id'], $setarr);
			$pkId = $oldnote['id'];
		} else {
			$oldnote['new'] = 0;
			$pkId = C::t('home_notification')->insert($setarr, true);
		}
		$banType = array('task');
		//上报消息中心
		if($_G['setting']['cloud_status'] && !in_array($type, $banType)) {
			$noticeService = Cloud::loadClass('Service_Client_Notification');
			if($oldnote['id']) {
				$noticeService->update($touid, $pkId, $setarr['from_num'], $setarr['dateline'], $note);
			} else {
				$extra = $type == 'post' ? array('pId' => $notevars['pid']) : array();
				$extra['notekey'] = $note;
				$noticeService->add($touid, $pkId, $type, $setarr['authorid'], $setarr['author'], $setarr['from_id'], $setarr['from_idtype'], $setarr['note'], $setarr['from_num'], $setarr['dateline'], $extra);
			}
		}

		//更新用户通知
		if(empty($oldnote['new'])) {
			C::t('common_member')->increase($touid, array('newprompt' => 1));
			$newprompt = C::t('common_member_newprompt')->fetch($touid);
			if($newprompt) {
				$newprompt['data'] = unserialize($newprompt['data']);
				if(!empty($newprompt['data'][$categoryname])) {
					$newprompt['data'][$categoryname] = intval($newprompt['data'][$categoryname]) + 1;
				} else {
					$newprompt['data'][$categoryname] = 1;
				}
				C::t('common_member_newprompt')->update($touid, array('data' => serialize($newprompt['data'])));
			} else {
				C::t('common_member_newprompt')->insert($touid, array($categoryname => 1));
			}
			require_once libfile('function/mail');
			$mail_subject = lang('notification', 'mail_to_user');
			sendmail_touser($touid, $mail_subject, $notestring, $frommyapp ? 'myapp' : $type);
		}

		//更新我的好友关系热度
		if(!$system && $_G['uid'] && $touid != $_G['uid']) {
			C::t('home_friend')->update_num_by_uid_fuid(1, $_G['uid'], $touid);
		}
	}

	/**
	* 发送管理通知
	* @param $type - 通知类型
	*/
	public static function manage_addnotify($type, $from_num = 0, $langvar = array()) {
		global $_G;
		$notifyusers = dunserialize($_G['setting']['notifyusers']);
		$notifytypes = explode(',', $_G['setting']['adminnotifytypes']);
		$notifytypes = array_flip($notifytypes);
		$notearr = array('from_id' => 1,'from_idtype' => $type, 'from_num' => $from_num);
		if($langvar) {
			$langkey = $langvar['langkey'];
			$notearr = array_merge($notearr, $langvar);
		} else {
			$langkey = 'manage_'.$type;
		}
		foreach($notifyusers as $uid => $user) {
			if($user['types'][$notifytypes[$type]]) {
				helper_notification::notification_add($uid, $type, $langkey, $notearr, 1, 4);
			}
		}
	}

	public function get_categorynum($newprompt_data) {
		global $_G;
		$categorynum = array();
		if(empty($newprompt_data) || !is_array($newprompt_data)) {
			return array();
		}
		foreach($newprompt_data as $key => $val) {
			if(in_array($key, array('follow', 'follower'))) {
				continue;
			}
			if(in_array($key, $_G['notice_structure']['mypost'])) {
				$categorynum['mypost'] += $val;
			} elseif(in_array($key, $_G['notice_structure']['interactive'])) {
				$categorynum['interactive'] += $val;
			}else{
				$categorynum[$key] = $val;
			}
		}
		return $categorynum;
	}

	public function update_newprompt($uid, $type) {
		global $_G;
		if($_G['member']['newprompt_num']) {
			$tmpprompt = $_G['member']['newprompt_num'];
			$num = 0;
			$updateprompt = 0;
			if(!empty($tmpprompt[$type])) {
				unset($tmpprompt[$type]);
				$updateprompt = true;
			}
			foreach($tmpprompt as $key => $val) {
				$num += $val;
			}
			if($num) {
				if($updateprompt) {
					C::t('common_member_newprompt')->update($uid, array('data' => serialize($tmpprompt)));
					C::t('common_member')->update($uid, array('newprompt'=>$num));
				}
			} else {
				C::t('common_member_newprompt')->delete($_G['uid']);
				C::t('common_member')->update($_G['uid'], array('newprompt'=>0));
			}
		}
	}
}

?>