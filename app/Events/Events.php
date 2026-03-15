<?php
/**
 * 事件常量定义
 */

namespace App\Events;

class Events
{
    // 用户事件
    const USER_REGISTERED = 'user.registered';
    const USER_LOGGED_IN = 'user.logged_in';
    const USER_LOGGED_OUT = 'user.logged_out';
    const USER_PASSWORD_RESET = 'user.password_reset';

    // 帖子事件
    const THREAD_CREATED = 'thread.created';
    const THREAD_UPDATED = 'thread.updated';
    const THREAD_DELETED = 'thread.deleted';

    // 回复事件
    const POST_CREATED = 'post.created';
    const POST_DELETED = 'post.deleted';

    // 管理事件
    const ADMIN_USER_UPDATED = 'admin.user.updated';
    const ADMIN_USER_DELETED = 'admin.user.deleted';
    const ADMIN_FORUM_CREATED = 'admin.forum.created';
    const ADMIN_FORUM_UPDATED = 'admin.forum.updated';
    const ADMIN_FORUM_DELETED = 'admin.forum.deleted';
    const ADMIN_THREAD_DELETED = 'admin.thread.deleted';
    const ADMIN_THREAD_TOPPED = 'admin.thread.topped';
    const ADMIN_THREAD_HIGHLIGHTED = 'admin.thread.highlighted';
    const ADMIN_SETTINGS_SAVED = 'admin.settings.saved';
    const ADMIN_IP_BLOCKED = 'admin.ip.blocked';
    const ADMIN_IP_UNBLOCKED = 'admin.ip.unblocked';
    const ADMIN_SENSITIVE_WORD_ADDED = 'admin.sensitive_word.added';
    const ADMIN_CACHE_CLEARED = 'admin.cache.cleared';
    const ADMIN_CLUSTER_NODE_CREATED = 'admin.cluster.node_created';
    const ADMIN_CLUSTER_NODE_TOGGLED = 'admin.cluster.node_toggled';
    const ADMIN_CLUSTER_NODE_DELETED = 'admin.cluster.node_deleted';

    // 版主操作事件
    const MOD_THREAD_LOCKED = 'mod.thread.locked';
    const MOD_THREAD_MOVED = 'mod.thread.moved';
    const MOD_POST_EDITED = 'mod.post.edited';
    const MOD_POST_DELETED = 'mod.post.deleted';

    // 回复事件
    const POST_UPDATED = 'post.updated';
}
