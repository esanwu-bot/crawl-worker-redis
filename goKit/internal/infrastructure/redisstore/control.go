package redisstore

import (
	"context"
	"time"
)

// 作业控制状态：running / paused / cancelled。
const (
	StateRunning   = "running"
	StatePaused    = "paused"
	StateCancelled = "cancelled"
)

// SetJobState 设置作业控制状态。
func (s *Store) SetJobState(ctx context.Context, jobID, state string) error {
	return s.rdb.Set(ctx, s.key("job:"+jobID+":state"), state, 0).Err()
}

// GetJobState 读取作业控制状态；未设置时返回 running。
func (s *Store) GetJobState(ctx context.Context, jobID string) string {
	if jobID == "" {
		return StateRunning
	}
	v, err := s.rdb.Get(ctx, s.key("job:"+jobID+":state")).Result()
	if err != nil || v == "" {
		return StateRunning
	}
	return v
}

// ParkTask 把任务暂存到作业的暂停队列（避免长时间占住 PEL）。
func (s *Store) ParkTask(ctx context.Context, jobID, payloadJSON string) error {
	key := s.key("job:" + jobID + ":parked")
	if err := s.rdb.RPush(ctx, key, payloadJSON).Err(); err != nil {
		return err
	}
	s.rdb.Expire(ctx, key, 7*24*time.Hour)
	return nil
}

// ResumeJob 把暂停队列中的任务重新投递回任务流，并置为 running。返回重投条数。
func (s *Store) ResumeJob(ctx context.Context, jobID string) (int, error) {
	key := s.key("job:" + jobID + ":parked")
	items, err := s.rdb.LRange(ctx, key, 0, -1).Result()
	if err != nil {
		return 0, err
	}
	n := 0
	for _, raw := range items {
		if _, err := s.PushRaw(ctx, raw); err != nil {
			continue
		}
		n++
	}
	s.rdb.Del(ctx, key)
	if err := s.SetJobState(ctx, jobID, StateRunning); err != nil {
		return n, err
	}
	return n, nil
}

// ParkedCount 暂停队列长度。
func (s *Store) ParkedCount(ctx context.Context, jobID string) int64 {
	n, _ := s.rdb.LLen(ctx, s.key("job:"+jobID+":parked")).Result()
	return n
}
